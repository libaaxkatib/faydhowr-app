<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\TemporaryReplacementStatus;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\TemporaryReplacement;
use App\Support\Hr\PositionSuccession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Approved rule: when an employee's separation is formally confirmed, that
 * employee's OPEN assignments end on the separation date.
 *
 *  - Only this employee's assignments with status Active are touched; ended and
 *    cancelled ones, and everyone else's, are left alone.
 *  - Nothing is deleted. The assignment keeps its salary history, notes and
 *    row; it just gains status Ended and an end date = the separation date (or
 *    its earlier planned end date, never later than the separation date).
 *  - If the separation date is before the assignment even started, the
 *    assignment is cancelled when that is safe (no payroll or replacements);
 *    otherwise it is ended on its own start date. It never ends before it starts.
 *  - Temporary replacements that cover a closed assignment, and replacements
 *    this employee was doing as a Temporary, end on the same date.
 *  - Every change gets its own audit event, and the result is returned so the
 *    separation audit event can list exactly what was closed.
 *  - The last paid day is the separation date itself (inclusive, calendar days,
 *    weekends included). If a successor assignment already starts on or before
 *    that day the separation is refused, so no calendar day is paid twice.
 *  - Rehiring does NOT reopen anything: a rehired employee needs a new assignment.
 */
class CloseAssignmentsOnSeparationAction
{
    public function __construct(private readonly CancelWorkAssignmentAction $cancel) {}

    /**
     * @return array{assignments: list<array<string, mixed>>, replacements_ended: list<int>, finalized_payroll_after_separation: list<string>}
     */
    public function handle(Employee $employee, string $separationDate, Admin $actor): array
    {
        $date = CarbonImmutable::parse($separationDate)->startOfDay();
        $closed = [];
        $closedWindows = [];

        $open = EmployeeWorkAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('status', WorkAssignmentStatus::Active)
            ->orderBy('id')
            ->get();

        foreach ($open as $assignment) {
            $previousEnd = $assignment->end_date?->toDateString();
            $note = "Closed because the employee was separated on {$date->toDateString()}.";

            if ($assignment->start_date->gt($date)) {
                try {
                    $this->cancel->handle($assignment, "Employee was separated on {$date->toDateString()}, before this assignment started.", $actor);
                    $closed[] = ['id' => $assignment->id, 'action' => 'cancelled', 'previous_end_date' => $previousEnd, 'new_end_date' => null];

                    continue;
                } catch (HttpResponseException) {
                    $newEnd = CarbonImmutable::parse($assignment->start_date);
                    $note = "Ended on its start date because the employee was separated on {$date->toDateString()}, before it started.";
                }
            } else {
                $newEnd = $assignment->end_date !== null && $assignment->end_date->lt($date) ? CarbonImmutable::parse($assignment->end_date) : $date;
            }

            // The leaver is paid through this day; a successor already starting on or before it would be paid for the same day.
            PositionSuccession::assertEndKeepsSuccessorClear($assignment, $newEnd);

            $assignment->update([
                'status' => WorkAssignmentStatus::Ended,
                'end_date' => $newEnd->toDateString(),
                'notes' => trim(($assignment->notes ? $assignment->notes."\n" : '').$note),
            ]);

            $closed[] = ['id' => $assignment->id, 'action' => 'ended', 'previous_end_date' => $previousEnd, 'new_end_date' => $newEnd->toDateString()];
            $closedWindows[$assignment->id] = $newEnd;

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Work assignment #{$assignment->id} ended on {$newEnd->toDateString()} because employee '{$employee->full_name}' was separated.",
                entityType: EmployeeWorkAssignment::class,
                entityId: $assignment->id,
                metadata: ['previous_end_date' => $previousEnd, 'separation_date' => $date->toDateString()],
            ));
        }

        $replacementsEnded = $this->endReplacements($employee, $closedWindows, $date);

        return [
            'assignments' => $closed,
            'replacements_ended' => $replacementsEnded,
            'finalized_payroll_after_separation' => $this->finalizedPayrollAfter($employee, $date),
        ];
    }

    /**
     * @param  array<int, CarbonImmutable>  $closedWindows  assignment id => new end date
     * @return list<int>
     */
    private function endReplacements(Employee $employee, array $closedWindows, CarbonImmutable $date): array
    {
        $ended = [];

        $query = TemporaryReplacement::query()->where('status', TemporaryReplacementStatus::Active)->where(function ($q) use ($employee, $closedWindows) {
            $q->where('replacement_employee_id', $employee->id);

            if ($closedWindows !== []) {
                $q->orWhereIn('work_assignment_id', array_keys($closedWindows));
            }
        });

        foreach ($query->get() as $replacement) {
            $endAt = $closedWindows[$replacement->work_assignment_id] ?? $date;
            $endAt = $replacement->end_date !== null && $replacement->end_date->lt($endAt) ? $replacement->end_date : $endAt;

            if ($endAt->lt($replacement->start_date)) {
                $endAt = CarbonImmutable::parse($replacement->start_date);
            }

            $replacement->update(['status' => TemporaryReplacementStatus::Ended, 'end_date' => $endAt->toDateString()]);
            $ended[] = $replacement->id;
        }

        return $ended;
    }

    /**
     * Months after the separation that are already approved/finalized and still
     * contain a payroll line for this employee: HR must review them (nothing is
     * changed automatically in a locked month).
     *
     * @return list<string>
     */
    private function finalizedPayrollAfter(Employee $employee, CarbonImmutable $date): array
    {
        return PayrollItem::query()
            ->where('employee_id', $employee->id)
            ->where('period', '>', $date->format('Y-m'))
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'finalized']))
            ->orderBy('period')
            ->pluck('period')
            ->unique()
            ->values()
            ->all();
    }
}
