<?php

namespace App\Support\Hr\Payroll;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollCorrection;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
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
 *  3. If a closed month WOULD change, the change is accepted only from a Super Admin or an HR Manager (CorrectionAuthority), with a reason; for anyone
 *     else the whole change is rolled back. The closed run and its lines are never edited: the difference is recorded as a PayrollCorrection.
 *  4. An overpayment is recovered from later salaries (see PayrollRunCalculator::applyOverpaymentRecovery); what payroll cannot take
 *     stays as an outstanding debt. An underpayment is recorded as an amount owed to the employee; it is paid ONLY by a Super Admin as a
 *     separate, traceable transaction (RecordCorrectionTransactionAction) - never inside payroll and never by rewriting the closed month.
 */
class ClosedPayrollCorrection
{
    private static bool $active = false;

    public function __construct(private readonly PayrollRunCalculator $calculator = new PayrollRunCalculator) {}

    /**
     * Owner decision 2026-11-04 (replaces the earlier "HR Manager may only add an employee"): a Super Admin AND an HR Manager may record a correction of
     * closed payroll for every assignment / salary / cover action. The correction is recorded exactly the same for both - the closed run is never
     * edited - and names who did it and in which role. Paying an underpayment stays Super Admin only (CorrectionAuthority).
     */
    private function mayCorrect(Admin $actor, string $action): bool
    {
        return CorrectionAuthority::mayCorrectClosedPayroll($actor);
    }

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

        // A month is closed for this change when the run is approved / finalized, or when some company of it is (the lines of the OTHER companies of
        // such a month are still open and are not counted: see netByEmployee).
        $periods = PayrollRun::query()
            ->where(fn ($q) => $q
                ->whereIn('status', PayrollPeriodLock::LOCKED_STATUSES)
                ->orWhereHas('sections', fn ($s) => $s->whereIn('status', PayrollRunSection::LOCKED_STATUSES)))
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

                if (! $this->mayCorrect($actor, $action)) {
                    throw new HttpResponseException(ApiResponse::error(
                        "This change would alter payroll for {$months}, which is approved or finalized. Only a Super Admin or an HR Manager can correct approved or finalized payroll. Nothing was changed.",
                        'CLOSED_PAYROLL_CORRECTION_NOT_ALLOWED',
                        403,
                    ));
                }

                // The new assignment is only known now: the correction names it (and where it is), so it can be traced from the payroll side.
                if ($result instanceof EmployeeWorkAssignment) {
                    $context['assignment_id'] ??= $result->id;
                    $context['new'] = [...($context['new'] ?? []), 'assignment_id' => $result->id, 'position_id' => $result->position_id, 'client_company_id' => $result->client_company_id, 'work_location_id' => $result->work_location_id];
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
     * Net pay per employee AND COMPANY for each closed month, calculated live WITHOUT recovery deductions (those are a consequence of corrections,
     * not an input to them). Each company owns its payroll, so a change is measured per company: a correction of Company A's pay is never mixed with
     * the same person's pay at Company B.
     *
     * @param  list<string>  $periods
     * @param  list<int>  $employeeIds
     * @return array<string, array<string, int>> period => "employee id:company key" => net cents
     */
    private function netByEmployee(array $periods, array $employeeIds): array
    {
        $result = [];
        $runs = PayrollRun::query()->whereIn('period', $periods)->get()->keyBy('period');

        foreach ($periods as $period) {
            $result[$period] = [];
            $run = $runs->get($period);
            // Null = every line of the month is closed (the run itself is approved / finalized, or it has no sections); else only the closed companies' lines.
            $closedCompanies = $run === null || in_array($run->status, PayrollPeriodLock::LOCKED_STATUSES, true)
                ? null
                : array_map(fn (?int $id): string => InputOwnership::key($id), PayrollSections::lockedCompanyIds($run));
            $items = $this->calculator->calculate($period, $employeeIds, false)['items'];
            $companyOf = PayrollSections::companyIdsByAssignment(array_values(array_unique(array_column($items, 'work_assignment_id'))));

            foreach ($items as $item) {
                $company = InputOwnership::key($companyOf[$item['work_assignment_id']] ?? null);

                if ($closedCompanies !== null && ! in_array($company, $closedCompanies, true)) {
                    continue;
                }

                $key = $item['employee_id'].':'.$company;
                $result[$period][$key] = ($result[$period][$key] ?? 0) + (int) $item['net_cents'];
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array<string, int>>  $before
     * @param  array<string, array<string, int>>  $after
     * @return array<string, array<string, int>> period => "employee id:company key" => (after - before), only non-zero
     */
    private function differences(array $before, array $after): array
    {
        $result = [];

        foreach ($after as $period => $lines) {
            foreach (array_unique([...array_keys($lines), ...array_keys($before[$period] ?? [])]) as $key) {
                $delta = ($lines[$key] ?? 0) - ($before[$period][$key] ?? 0);

                if ($delta !== 0) {
                    $result[$period][$key] = $delta;
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array<string, int>>  $differences
     * @return list<array{period: string, employee_id: int, client_company_id: int|null, change_cents: int}>
     */
    private function describe(array $differences): array
    {
        $rows = [];

        foreach ($differences as $period => $lines) {
            foreach ($lines as $key => $delta) {
                [$employeeId, $company] = explode(':', (string) $key, 2);
                $rows[] = ['period' => $period, 'employee_id' => (int) $employeeId, 'client_company_id' => InputOwnership::companyOf($company), 'change_cents' => $delta];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, int>>  $differences
     * @param  array<string, mixed>  $context
     */
    private function record(Admin $actor, string $action, array $differences, string $reason, array $context): void
    {
        $batch = (string) Str::uuid();

        foreach ($differences as $period => $lines) {
            $run = PayrollRun::query()->where('period', $period)->firstOrFail();

            foreach ($lines as $key => $delta) {
                [$employeeId, $companyKey] = explode(':', (string) $key, 2);
                $employeeId = (int) $employeeId;
                $companyId = InputOwnership::companyOf($companyKey);

                // Only the lines that are closed (their company's section is approved / finalized, or the whole run is) and that belong to THIS company are corrected.
                $runClosed = in_array($run->status, PayrollPeriodLock::LOCKED_STATUSES, true);
                $items = PayrollItem::query()->with('section')->where('payroll_run_id', $run->id)->where('employee_id', $employeeId)->whereNull('voided_at')
                    ->when(! $runClosed, fn ($q) => $q->whereHas('section', fn ($s) => $s->whereIn('status', PayrollRunSection::LOCKED_STATUSES)))
                    ->orderBy('id')->get();
                $companyOf = PayrollSections::companyIdsByAssignment($items->pluck('work_assignment_id')->unique()->map(fn ($id): int => (int) $id)->values()->all());
                $items = $items->filter(fn (PayrollItem $i): bool => InputOwnership::key($companyOf[$i->work_assignment_id] ?? null) === $companyKey)->values();
                $recorded = (int) $items->sum('net_cents');
                $corrected = $recorded + $delta;
                $difference = $recorded - $corrected; // > 0: paid more than it should have been

                $correction = PayrollCorrection::query()->create([
                    'batch_key' => $batch,
                    'employee_id' => $employeeId,
                    'client_company_id' => $companyId,
                    'work_assignment_id' => $context['assignment_id'] ?? null,
                    'payroll_run_id' => $run->id,
                    'payroll_item_id' => $items->first()?->id,
                    'period' => $period,
                    'kind' => $difference > 0 ? PayrollCorrection::KIND_OVERPAYMENT : PayrollCorrection::KIND_UNDERPAYMENT,
                    'action' => $action,
                    'recorded_net_cents' => $recorded,
                    'corrected_net_cents' => $corrected,
                    'difference_cents' => $difference,
                    'outstanding_cents' => abs($difference),
                    'status' => PayrollCorrection::STATUS_OPEN,
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
                        // Who recorded it and in which role: an HR Manager may record an assignment correction (HR_MANAGER_ACTIONS), a Super Admin any.
                        'corrected_by_admin_id' => $actor->id,
                        'corrected_by_role' => $actor->role->value,
                        'employee_id' => $employeeId,
                        'period' => $period,
                        'payroll_run_id' => $run->id,
                        'run_status' => $run->status,
                        // The ONE company whose closed payroll this correction is about; an overpayment is recovered from that company's pay only.
                        'client_company_id' => $companyId,
                        'client_company_ids' => [$companyId],
                        'recorded_net_cents' => $recorded,
                        'corrected_net_cents' => $corrected,
                        'difference_cents' => $difference,
                        'old_values' => $context['old'] ?? null,
                        'new_values' => $context['new'] ?? null,
                        'reason' => $reason,
                        'recovery' => $difference > 0 ? 'deducted from the following salaries until repaid; any balance stays an outstanding debt' : 'underpayment: owed to the employee, paid only by a Super Admin as a separate transaction',
                    ],
                ));
            }
        }
    }
}
