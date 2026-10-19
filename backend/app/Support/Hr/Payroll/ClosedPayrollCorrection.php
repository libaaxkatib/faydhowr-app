<?php

namespace App\Support\Hr\Payroll;

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\PayrollCorrection;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Wraps a change to assignment / salary data so that it can never silently rewrite approved or finalized payroll.
 *
 *  1. Only months that are approved or finalized are looked at, and only from the first date the change can reach.
 *  2. Each of those months is calculated for the employees involved BEFORE the change and AFTER it (the same calculator the payroll
 *     run uses). If no closed month changes, the change is allowed for whoever may make it - months it does not touch are not locked.
 *  3. If a closed month WOULD change, the change is accepted only from a Super Admin, with a reason; for anyone else the whole change
 *     is rolled back. The closed run and its lines are never edited: the difference is recorded as a PayrollCorrection.
 *  4. An overpayment is recovered from later salaries (see PayrollRunCalculator::applyOverpaymentRecovery). An underpayment is
 *     recorded and visible, but no repayment rule has been approved by the owner, so nothing is paid automatically.
 */
class ClosedPayrollCorrection
{
    private static bool $active = false;

    public function __construct(private readonly PayrollRunCalculator $calculator = new PayrollRunCalculator) {}

    /**
     * @template T
     *
     * @param  list<int>  $employeeIds  every employee whose pay the change can touch
     * @param  string  $earliestDate  the first calendar date (Y-m-d) the change can reach
     * @param  array<string, mixed>  $context  what changed: assignment_id, old, new
     * @param  Closure(): T  $change
     * @return T
     */
    public function run(Admin $actor, string $action, array $employeeIds, string $earliestDate, ?string $reason, array $context, Closure $change): mixed
    {
        if (self::$active) {
            return $change();
        }

        $periods = PayrollRun::query()
            ->whereIn('status', PayrollPeriodLock::LOCKED_STATUSES)
            ->where('period', '>=', substr($earliestDate, 0, 7))
            ->orderBy('period')
            ->pluck('period')
            ->all();

        if ($periods === []) {
            return $change();
        }

        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        $reason = $reason === null ? null : trim($reason);

        self::$active = true;

        try {
            return DB::transaction(function () use ($actor, $action, $employeeIds, $periods, $reason, $context, $change) {
                $before = $this->netByEmployee($periods, $employeeIds);
                $result = $change();
                $after = $this->netByEmployee($periods, $employeeIds);

                $differences = $this->differences($before, $after);

                if ($differences === []) {
                    return $result;
                }

                $months = implode(', ', array_keys($differences));

                if ($actor->role !== AdminRole::SuperAdmin) {
                    throw new HttpResponseException(ApiResponse::error(
                        "This change would alter payroll for {$months}, which is approved or finalized. Only a Super Admin can correct approved or finalized payroll. Nothing was changed.",
                        'PAYROLL_CORRECTION_SUPER_ADMIN_ONLY',
                        403,
                    ));
                }

                if ($reason === null || mb_strlen($reason) < 5) {
                    throw new HttpResponseException(ApiResponse::error(
                        "This change alters payroll for {$months}, which is approved or finalized. Enter a correction reason (at least 5 characters) to record it as a correction. Nothing was changed.",
                        'CORRECTION_REASON_REQUIRED',
                        422,
                        ['correction_reason' => ['A correction reason is required.'], 'affected_periods' => array_keys($differences), 'differences' => $this->describe($differences)],
                    ));
                }

                $this->record($actor, $action, $differences, $reason, $context);

                return $result;
            });
        } finally {
            self::$active = false;
        }
    }

    /**
     * Net pay per employee for each closed month, calculated live WITHOUT recovery deductions (those are a consequence of corrections,
     * not an input to them).
     *
     * @param  list<string>  $periods
     * @param  list<int>  $employeeIds
     * @return array<string, array<int, int>> period => employee id => net cents
     */
    private function netByEmployee(array $periods, array $employeeIds): array
    {
        $result = [];

        foreach ($periods as $period) {
            $result[$period] = [];

            foreach ($this->calculator->calculate($period, $employeeIds, false)['items'] as $item) {
                $result[$period][$item['employee_id']] = ($result[$period][$item['employee_id']] ?? 0) + (int) $item['net_cents'];
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array<int, int>>  $before
     * @param  array<string, array<int, int>>  $after
     * @return array<string, array<int, int>> period => employee id => (after - before), only non-zero
     */
    private function differences(array $before, array $after): array
    {
        $result = [];

        foreach ($after as $period => $employees) {
            foreach (array_unique([...array_keys($employees), ...array_keys($before[$period] ?? [])]) as $employeeId) {
                $delta = ($employees[$employeeId] ?? 0) - ($before[$period][$employeeId] ?? 0);

                if ($delta !== 0) {
                    $result[$period][$employeeId] = $delta;
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array<int, int>>  $differences
     * @return list<array{period: string, employee_id: int, change_cents: int}>
     */
    private function describe(array $differences): array
    {
        $rows = [];

        foreach ($differences as $period => $employees) {
            foreach ($employees as $employeeId => $delta) {
                $rows[] = ['period' => $period, 'employee_id' => $employeeId, 'change_cents' => $delta];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, array<int, int>>  $differences
     * @param  array<string, mixed>  $context
     */
    private function record(Admin $actor, string $action, array $differences, string $reason, array $context): void
    {
        $batch = (string) Str::uuid();

        foreach ($differences as $period => $employees) {
            $run = PayrollRun::query()->where('period', $period)->firstOrFail();

            foreach ($employees as $employeeId => $delta) {
                $items = PayrollItem::query()->where('payroll_run_id', $run->id)->where('employee_id', $employeeId)->whereNull('voided_at')->orderBy('id')->get();
                $recorded = (int) $items->sum('net_cents');
                $corrected = $recorded + $delta;
                $difference = $recorded - $corrected; // > 0: paid more than it should have been

                $correction = PayrollCorrection::query()->create([
                    'batch_key' => $batch,
                    'employee_id' => $employeeId,
                    'work_assignment_id' => $context['assignment_id'] ?? null,
                    'payroll_run_id' => $run->id,
                    'payroll_item_id' => $items->first()?->id,
                    'period' => $period,
                    'kind' => $difference > 0 ? PayrollCorrection::KIND_OVERPAYMENT : PayrollCorrection::KIND_UNDERPAYMENT,
                    'action' => $action,
                    'recorded_net_cents' => $recorded,
                    'corrected_net_cents' => $corrected,
                    'difference_cents' => $difference,
                    'outstanding_cents' => $difference > 0 ? $difference : 0,
                    'status' => $difference > 0 ? PayrollCorrection::STATUS_OPEN : PayrollCorrection::STATUS_PENDING_POLICY,
                    'reason' => $reason,
                    'old_values' => $context['old'] ?? null,
                    'new_values' => $context['new'] ?? null,
                    'corrected_by' => $actor->id,
                ]);

                event(AuditEvent::record(
                    action: AuditAction::Create,
                    admin: $actor,
                    description: "Payroll correction recorded for {$period} ({$correction->kind}, ".PayrollMoney::format(abs($difference)).' USD) after an assignment change: '.$reason,
                    entityType: PayrollCorrection::class,
                    entityId: $correction->id,
                    metadata: [
                        'event' => 'payroll_corrected',
                        'action' => $action,
                        'employee_id' => $employeeId,
                        'period' => $period,
                        'payroll_run_id' => $run->id,
                        'run_status' => $run->status,
                        'recorded_net_cents' => $recorded,
                        'corrected_net_cents' => $corrected,
                        'difference_cents' => $difference,
                        'old_values' => $context['old'] ?? null,
                        'new_values' => $context['new'] ?? null,
                        'reason' => $reason,
                        'recovery' => $difference > 0 ? 'deducted from the following salaries until repaid' : 'underpayment: no repayment rule approved, nothing paid automatically',
                    ],
                ));
            }
        }
    }
}
