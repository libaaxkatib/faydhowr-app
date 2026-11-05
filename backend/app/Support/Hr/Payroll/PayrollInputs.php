<?php

namespace App\Support\Hr\Payroll;

use App\Enums\AssignmentType;
use App\Enums\AttendanceStatus;
use App\Enums\LeaveType;
use App\Enums\WorkAssignmentStatus;
use App\Models\AssignmentAbsence;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLeave;
use App\Models\EmployeePenalty;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollAdjustment;
use App\Models\TemporaryReplacement;
use App\Support\Hr\AbsenceCycles;
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

    /** @var array<int, list<array{0: string, 1: ?string}>> assignment id => the days its holder is away (replacement cycles), as inclusive ranges */
    private array $absenceWindows = [];

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

    /** @var array<int, Collection<int, EmployeePenalty>> employee id => penalties of this or an earlier month with something still owed (owed_cents) */
    private array $owedPenalties = [];

    /** @var array<int, Collection<int, EmployeeAdvance>> */
    private array $owedAdvances = [];

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

        // The absence windows (replacement cycles) of the assignments being calculated and of the ones they replace: the replaced one is not paid for them.
        foreach (array_chunk(array_values(array_unique([...$assignmentIds, ...$replacedIds])), self::CHUNK) as $chunk) {
            $rows = AssignmentAbsence::query()->whereIn('work_assignment_id', $chunk)->orderBy('absent_from')->orderBy('id')->get();

            foreach ($rows->groupBy('work_assignment_id') as $id => $group) {
                $self->absenceWindows[(int) $id] = AbsenceCycles::rangesOf($group);
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

        // What is still owed on penalties / advances registered for this month or an earlier one (Release 1B: the net is never below $0, the rest carries on).
        $owed = DeductionBalances::owedFor($employeeIds, $period);
        $self->owedPenalties = $owed['penalties'];
        $self->owedAdvances = $owed['advances'];

        foreach (array_chunk($employeeIds, self::CHUNK) as $chunk) {
            foreach (EmployeePenalty::query()->active()->whereIn('employee_id', $chunk)->where('payroll_period', $period)->orderBy('id')->get()->groupBy('employee_id') as $id => $group) {
                $self->penalties[(int) $id] = $group->values();
            }

            foreach (EmployeeAdvance::query()->active()->whereIn('employee_id', $chunk)->where('payroll_period', $period)->orderBy('id')->get()->groupBy('employee_id') as $id => $group) {
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

    /**
     * The days of an assignment that are NOT paid to it: those its Replacements hold, and those its holder is away. They may overlap each other (the
     * proration counts a day once whichever  ranges contain it), and a gap day sits in the absence only.
     *
     * @return list<array{0: string, 1: ?string}>
     */
    public function replacementRanges(int $assignmentId): array
    {
        return [...($this->ranges[$assignmentId] ?? []), ...($this->absenceWindows[$assignmentId] ?? [])];
    }

    /**
     * Only the days Replacements hold, and only the absence windows: payroll compares the two to find the days nobody covers.
     *
     * @return list<array{0: string, 1: ?string}>
     */
    public function coverRangesOf(int $assignmentId): array
    {
        return $this->ranges[$assignmentId] ?? [];
    }

    /** @return list<array{0: string, 1: ?string}> */
    public function absenceWindowsOf(int $assignmentId): array
    {
        return $this->absenceWindows[$assignmentId] ?? [];
    }

    /**
     * True when $day falls inside one of the assignment's absence windows.
     */
    public function isAwayOn(int $assignmentId, string $day): bool
    {
        foreach ($this->absenceWindows[$assignmentId] ?? [] as [$from, $to]) {
            if ($day >= $from && ($to === null || $day <= $to)) {
                return true;
            }
        }

        return false;
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

    /** @return Collection<int, EmployeePenalty> */
    public function owedPenalties(int $employeeId): Collection
    {
        return $this->owedPenalties[$employeeId] ?? new Collection;
    }

    /** @return Collection<int, EmployeeAdvance> */
    public function owedAdvances(int $employeeId): Collection
    {
        return $this->owedAdvances[$employeeId] ?? new Collection;
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
