<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\AssignmentSalaryHistory;
use App\Models\EmployeeWorkAssignment;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollMoney;
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
            $appliedToReplacements = $this->applyToReplacements($assignment, $effective, $amount, $currency, $frequency, $actor);

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
                    'applied_to_replacement_assignment_ids' => $appliedToReplacements,
                ],
            ));

            return $assignment->refresh();
        });
    }

    /**
     * Owner rule: a Replacement's salary is the replaced Permanent's. When the Permanent's salary changes, the new salary applies to every
     * Replacement that holds that position for the WHOLE month of the change - it is never prorated by the day the change took effect.
     * Each Replacement gets its own salary-history row (from the first day of that month, or its own first day if later), so the history is
     * kept and the payroll engine needs no special case. Replacements that ended before that month are not touched. Returns the ids changed.
     *
     * @return list<int>
     */
    private function applyToReplacements(EmployeeWorkAssignment $source, CarbonImmutable $effective, string $amount, string $currency, string $frequency, Admin $actor): array
    {
        if (($source->assignment_type?->value ?? 'permanent') !== 'permanent') {
            return [];
        }

        $monthStart = $effective->startOfMonth();
        $applied = [];

        foreach ($this->replacementsOf($source) as $dependent) {
            $from = $monthStart->max(CarbonImmutable::parse($dependent->start_date)->startOfDay());

            if ($dependent->end_date !== null && $dependent->end_date->lt($from)) {
                continue;
            }

            $existing = AssignmentSalaryHistory::query()->where('work_assignment_id', $dependent->id)->whereDate('effective_from', $from->toDateString())->first();

            if ($existing !== null && PayrollMoney::toCents($existing->amount) === PayrollMoney::toCents($amount) && $existing->currency === $currency && $existing->frequency->value === $frequency) {
                continue;
            }

            $previous = $existing ? ['amount' => (string) $existing->amount, 'currency' => $existing->currency, 'frequency' => $existing->frequency->value] : null;
            $reason = "Permanent assignment #{$source->id} salary changed effective {$effective->toDateString()}; applied to the whole month from {$from->toDateString()} (a Replacement is never prorated by the day of the change)";
            $row = ['amount' => $amount, 'currency' => $currency, 'frequency' => $frequency, 'reason' => $reason, 'changed_by' => $actor->id];

            if ($existing !== null) {
                $existing->update($row);
            } else {
                AssignmentSalaryHistory::query()->create(['work_assignment_id' => $dependent->id, 'effective_from' => $from->toDateString(), ...$row]);
            }

            $this->syncCurrentSalary($dependent);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Salary of Replacement assignment #{$dependent->id} follows Permanent assignment #{$source->id}: {$amount} {$currency} for the whole month from {$from->toDateString()}.",
                entityType: EmployeeWorkAssignment::class,
                entityId: $dependent->id,
                metadata: [
                    'event' => 'salary_changed',
                    'source' => 'permanent_salary_change',
                    'permanent_assignment_id' => $source->id,
                    'permanent_change_effective' => $effective->toDateString(),
                    'applied_from' => $from->toDateString(),
                    'amount' => $amount,
                    'currency' => $currency,
                    'frequency' => $frequency,
                    'previous_value_at_same_date' => $previous,
                ],
            ));

            $applied[] = $dependent->id;
        }

        return $applied;
    }

    /**
     * Every Replacement that holds the position of this Permanent assignment, including a Replacement of a Replacement (the chain stops at
     * the next Permanent assignment, which has its own salary).
     *
     * @return list<EmployeeWorkAssignment>
     */
    private function replacementsOf(EmployeeWorkAssignment $source): array
    {
        $found = [];
        $queue = [$source->id];

        while ($queue !== []) {
            $parentId = array_shift($queue);

            foreach (EmployeeWorkAssignment::query()->where('replaces_assignment_id', $parentId)->where('assignment_type', 'replacement')->where('status', '!=', 'cancelled')->orderBy('id')->get() as $child) {
                $found[$child->id] = $child;
                $queue[] = $child->id;
            }
        }

        return array_values($found);
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
