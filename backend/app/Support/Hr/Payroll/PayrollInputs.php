<?php

namespace App\Support\Hr\Payroll;

use App\Enums\AssignmentType;
use App\Enums\AttendanceStatus;
use App\Enums\LeaveType;
use App\Enums\WorkAssignmentStatus;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLeave;
use App\Models\EmployeePenalty;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollAdjustment;
use App\Models\TemporaryReplacement;
use Illuminate\Support\Collection;

/**
 * What one payroll calculation reads PER ASSIGNMENT and PER EMPLOYEE (the Temporary covers, penalties, advances, unpaid leaves, absences, pending
 * adjustments, the replaced and the replacing assignment), read ONCE for every assignment of the run.
 *
 * The calculator used to ask the database for each of these separately for every assignment: about 11 queries per assignment, one after the other. On
 * production every query costs ~10 ms (the web container and Postgres are separate machines), so 302 assignments took more than the 30 s PHP allows a
 * request and "Generate / refresh draft" died with "Failed to fetch". Here the same questions are asked with `whereIn` - the same filters, the same rows -
 * so a month costs a fixed, small number of queries however many people there are.
 *
 * Nothing is decided here: every getter returns exactly what the query it replaces returned for that assignment or employee, and `null` when the
 * assignment/employee was not part of the load, in which case the caller runs the original query. So the calculation can never see less than before.
 */
final class PayrollInputs
{
    /** Ids per `whereIn` (keeps every database within its limit on bound parameters). */
    private const CHUNK = 500;

    /** @var array<int, true> */
    private array $assignmentIds = [];

    /** @var array<int, true> */
    private array $employeeIds = [];

    /** @var array<int, list<array{0: string, 1: ?string}>> replaced assignment id => the date ranges its Replacements hold */
    private array $ranges = [];

    /** @var array<int, EmployeeWorkAssignment|null> assignment id => its earliest non-cancelled successor */
    private array $successors = [];

    /** @var array<int, EmployeeWorkAssignment|null> assignment id => the assignment it replaces (whatever its status) */
    private array $predecessors = [];

    /** @var array<int, Collection<int, TemporaryReplacement>> */
    private array $covers = [];

    /** @var array<int, Collection<int, EmployeePenalty>> */
    private array $penalties = [];

    /** @var array<int, Collection<int, EmployeeAdvance>> */
    private array $advances = [];

    /** @var array<int, Collection<int, EmployeeLeave>> */
    private array $leaves = [];

    /** @var array<int, Collection<int, EmployeeAttendance>> */
    private array $absences = [];

    /** @var array<int, Collection<int, PayrollAdjustment>> */
    private array $pendingAdjustments = [];

    /** @param  Collection<int, EmployeeWorkAssignment>  $assignments  the assignments being calculated */
    public static function load(string $period, Collection $assignments, \DateTimeImmutable $first, \DateTimeImmutable $last): self
    {
        $self = new self;
        $assignmentIds = $assignments->pluck('id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $employeeIds = $assignments->pluck('employee_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

        if ($assignmentIds === []) {
            return $self;
        }

        $self->assignmentIds = array_fill_keys($assignmentIds, true);
        $self->employeeIds = array_fill_keys($employeeIds, true);
        $firstDay = $first->format('Y-m-d');
        $lastDay = $last->format('Y-m-d');

        // The Replacements / successors of an assignment (non-cancelled), in the order "earliest start, then id".
        foreach (array_chunk($assignmentIds, self::CHUNK) as $chunk) {
            $successors = EmployeeWorkAssignment::query()
                ->whereIn('replaces_assignment_id', $chunk)
                ->where('status', '!=', WorkAssignmentStatus::Cancelled)
                ->orderBy('start_date')->orderBy('id')
                ->get();

            foreach ($successors->groupBy('replaces_assignment_id') as $replacedId => $group) {
                $self->successors[(int) $replacedId] = $group->first();
                $self->ranges[(int) $replacedId] = $group
                    ->filter(fn (EmployeeWorkAssignment $s): bool => $s->assignment_type === AssignmentType::Replacement)
                    ->sortBy('id')
                    ->map(fn (EmployeeWorkAssignment $s): array => [$s->start_date->toDateString(), $s->end_date?->toDateString()])
                    ->values()
                    ->all();
            }
        }

        // The assignment each one replaces (loaded whatever its status: the calculator checks the status itself).
        $replacedIds = $assignments->pluck('replaces_assignment_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $predecessorById = collect();

        foreach (array_chunk($replacedIds, self::CHUNK) as $chunk) {
            $predecessorById = $predecessorById->union(EmployeeWorkAssignment::query()->whereIn('id', $chunk)->get()->keyBy('id'));
        }

        foreach ($assignments as $assignment) {
            if ($assignment->replaces_assignment_id !== null) {
                $self->predecessors[(int) $assignment->id] = $predecessorById->get((int) $assignment->replaces_assignment_id);
            }
        }

        foreach (array_chunk($assignmentIds, self::CHUNK) as $chunk) {
            foreach (TemporaryReplacement::query()->with('replacementEmployee')->whereIn('work_assignment_id', $chunk)->orderBy('id')->get()->groupBy('work_assignment_id') as $id => $group) {
                $self->covers[(int) $id] = $group->values();
            }

            // Pending adjustments of the period that sit on a line of this assignment.
            $adjustments = PayrollAdjustment::query()
                ->where('applies_in_period', $period)->where('status', 'pending')
                ->whereHas('item', fn ($q) => $q->whereIn('work_assignment_id', $chunk))
                ->with('item')
                ->orderBy('id')
                ->get();

            foreach ($adjustments->groupBy(fn (PayrollAdjustment $a): int => (int) $a->item->work_assignment_id) as $id => $group) {
                $self->pendingAdjustments[(int) $id] = $group->values();
            }
        }

        foreach (array_chunk($employeeIds, self::CHUNK) as $chunk) {
            foreach (EmployeePenalty::query()->whereIn('employee_id', $chunk)->where('payroll_period', $period)->orderBy('id')->get()->groupBy('employee_id') as $id => $group) {
                $self->penalties[(int) $id] = $group->values();
            }

            foreach (EmployeeAdvance::query()->whereIn('employee_id', $chunk)->where('payroll_period', $period)->orderBy('id')->get()->groupBy('employee_id') as $id => $group) {
                $self->advances[(int) $id] = $group->values();
            }

            $leaves = EmployeeLeave::query()
                ->whereIn('employee_id', $chunk)
                ->where('leave_type', LeaveType::Unpaid)
                ->whereDate('start_date', '<=', $lastDay)
                ->whereDate('end_date', '>=', $firstDay)
                ->orderBy('id')
                ->get();

            foreach ($leaves->groupBy('employee_id') as $id => $group) {
                $self->leaves[(int) $id] = $group->values();
            }

            $absences = EmployeeAttendance::query()
                ->whereIn('employee_id', $chunk)
                ->where('status', AttendanceStatus::Absent)
                ->whereDate('date', '>=', $firstDay)->whereDate('date', '<=', $lastDay)
                ->orderBy('id')
                ->get();

            foreach ($absences->groupBy('employee_id') as $id => $group) {
                $self->absences[(int) $id] = $group->values();
            }
        }

        return $self;
    }

    public function hasAssignment(int $assignmentId): bool
    {
        return isset($this->assignmentIds[$assignmentId]);
    }

    public function hasEmployee(int $employeeId): bool
    {
        return isset($this->employeeIds[$employeeId]);
    }

    /** @return list<array{0: string, 1: ?string}> */
    public function replacementRanges(int $assignmentId): array
    {
        return $this->ranges[$assignmentId] ?? [];
    }

    /** @return Collection<int, TemporaryReplacement> */
    public function covers(int $assignmentId): Collection
    {
        return $this->covers[$assignmentId] ?? new Collection;
    }

    /** @return Collection<int, EmployeePenalty> */
    public function penalties(int $employeeId): Collection
    {
        return $this->penalties[$employeeId] ?? new Collection;
    }

    /** @return Collection<int, EmployeeAdvance> */
    public function advances(int $employeeId): Collection
    {
        return $this->advances[$employeeId] ?? new Collection;
    }

    /** @return Collection<int, EmployeeLeave> */
    public function unpaidLeaves(int $employeeId): Collection
    {
        return $this->leaves[$employeeId] ?? new Collection;
    }

    /** @return Collection<int, EmployeeAttendance> */
    public function absences(int $employeeId): Collection
    {
        return $this->absences[$employeeId] ?? new Collection;
    }

    /** @return Collection<int, PayrollAdjustment> */
    public function pendingAdjustments(int $assignmentId): Collection
    {
        return $this->pendingAdjustments[$assignmentId] ?? new Collection;
    }

    public function predecessor(EmployeeWorkAssignment $assignment): ?EmployeeWorkAssignment
    {
        return $this->predecessors[(int) $assignment->id] ?? null;
    }

    /** The earliest non-cancelled assignment that replaces this one. */
    public function successor(int $assignmentId): ?EmployeeWorkAssignment
    {
        return $this->successors[$assignmentId] ?? null;
    }

    /**
     * True only when it is KNOWN that this assignment is in no chain: nobody (non-cancelled) replaces it and it replaces nobody (non-cancelled). The
     * chain walk then has nothing to find and is skipped; for every other assignment the original walk runs unchanged.
     */
    public function isInNoChain(EmployeeWorkAssignment $assignment): bool
    {
        if (! $this->hasAssignment((int) $assignment->id) || $this->successor((int) $assignment->id) !== null) {
            return false;
        }

        if ($assignment->replaces_assignment_id === null) {
            return true;
        }

        $predecessor = $this->predecessor($assignment);

        return $predecessor === null || $predecessor->status === WorkAssignmentStatus::Cancelled;
    }
}
