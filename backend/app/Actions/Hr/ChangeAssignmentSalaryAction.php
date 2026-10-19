<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\AssignmentSalaryHistory;
use App\Models\EmployeeWorkAssignment;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Records a salary for an assignment from an effective date, never
 * overwriting the previous salary: the earlier row simply stops applying the
 * day before. Re-entering the SAME effective date is a correction of that row
 * (the old values are kept in the audit event). The assignment's current
 * salary columns are re-synced to the salary in effect today. Changes in or
 * before an approved/finalized payroll month are refused; those need a payroll
 * adjustment instead.
 */
class ChangeAssignmentSalaryAction
{
    public function handle(
        EmployeeWorkAssignment $assignment,
        string $amount,
        string $currency,
        string $frequency,
        string $effectiveFrom,
        ?string $reason,
        Admin $actor,
    ): EmployeeWorkAssignment {
        $effective = CarbonImmutable::parse($effectiveFrom)->startOfDay();

        if ($effective->lt(CarbonImmutable::parse($assignment->start_date)->startOfDay())) {
            throw new HttpResponseException(ApiResponse::error(
                'A salary cannot take effect before the assignment starts.',
                'SALARY_BEFORE_ASSIGNMENT_START',
                422,
            ));
        }

        // A salary change that reaches an approved or finalized month is handled one level up (ClosedPayrollCorrection), where the
        // acting Super Admin and the correction reason are known.
        return DB::transaction(function () use ($assignment, $amount, $currency, $frequency, $effective, $reason, $actor) {
            $existing = AssignmentSalaryHistory::query()
                ->where('work_assignment_id', $assignment->id)
                ->whereDate('effective_from', $effective->toDateString())
                ->first();

            $previous = $existing
                ? ['amount' => (string) $existing->amount, 'currency' => $existing->currency, 'frequency' => $existing->frequency->value]
                : null;

            if ($existing !== null) {
                $existing->update(['amount' => $amount, 'currency' => $currency, 'frequency' => $frequency, 'reason' => $reason ?? $existing->reason, 'changed_by' => $actor->id]);
            } else {
                AssignmentSalaryHistory::query()->create([
                    'work_assignment_id' => $assignment->id,
                    'effective_from' => $effective->toDateString(),
                    'amount' => $amount,
                    'currency' => $currency,
                    'frequency' => $frequency,
                    'reason' => $reason,
                    'changed_by' => $actor->id,
                ]);
            }

            $this->syncCurrentSalary($assignment);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Salary for work assignment #{$assignment->id} ".($existing ? 'corrected' : 'changed')." effective {$effective->toDateString()}.",
                entityType: EmployeeWorkAssignment::class,
                entityId: $assignment->id,
                metadata: [
                    'effective_from' => $effective->toDateString(),
                    'amount' => $amount,
                    'currency' => $currency,
                    'frequency' => $frequency,
                    'previous_value_at_same_date' => $previous,
                    'reason' => $reason,
                ],
            ));

            return $assignment->refresh();
        });
    }

    /**
     * Sets the assignment's current salary columns to the salary in effect
     * today (or the earliest row if every row is still in the future).
     */
    public function syncCurrentSalary(EmployeeWorkAssignment $assignment): void
    {
        $today = CarbonImmutable::now()->toDateString();

        $current = AssignmentSalaryHistory::query()
            ->where('work_assignment_id', $assignment->id)
            ->whereDate('effective_from', '<=', $today)
            ->orderByDesc('effective_from')
            ->first()
            ?? AssignmentSalaryHistory::query()
                ->where('work_assignment_id', $assignment->id)
                ->orderBy('effective_from')
                ->first();

        if ($current === null) {
            return;
        }

        $assignment->update([
            'salary_amount' => $current->amount,
            'salary_currency' => $current->currency,
            'salary_frequency' => $current->frequency,
        ]);
    }
}
