<?php

namespace App\Support\Hr\Payroll;

/**
 * Turns one payroll line (stored or live) into the payload shown by the
 * per-employee payroll summary and the payroll rollup. The original summary
 * keys are kept, so existing screens and API consumers keep working; the new
 * keys explain the proration, the replacement and unpaid-leave deductions,
 * and whether the figures are an estimate.
 */
final class PayrollSummaryPresenter
{
    public const ESTIMATE_NOTE = 'Estimate: there is no approved or finalized payroll run for this month yet. These figures are calculated live with the same rules the payroll run uses and can change until the run is approved.';

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $view  the PayrollPeriodView result the line came from
     * @return array<string, mixed>
     */
    public static function present(array $line, array $view, ?string $locationType): array
    {
        $deductions = $line['deductions'] ?? [];
        $of = fn (string $kind): array => array_values(array_filter($deductions, fn (array $d): bool => $d['kind'] === $kind));
        $sum = fn (string $kind): int => (int) array_sum(array_column($of($kind), 'amount_cents'));
        $fmt = fn (int $cents): string => PayrollMoney::format($cents);

        $segments = $line['calc_snapshot']['salary_segments'] ?? [];
        $monthlyCents = $segments === [] ? 0 : (int) end($segments)['monthly_cents'];
        $daysInMonth = (int) $line['days_in_month'];
        $absence = $of('absence')[0] ?? null;

        return [
            'employee_id' => $line['employee_id'],
            'work_assignment_id' => $line['work_assignment_id'],
            'payroll_period' => $view['period'],
            'payable' => true,
            'not_payable_reason' => null,
            'location_type' => $locationType,
            'currency' => PayrollMoney::CURRENCY,
            'monthly_salary' => $fmt($monthlyCents),
            'days_in_month' => $daysInMonth,
            'eligible_days' => (int) $line['eligible_days'],
            'daily_rate' => $fmt((int) PayrollMoney::roundDiv($monthlyCents, $daysInMonth)),
            'gross_pay' => $fmt((int) $line['gross_cents']),
            'absent_days' => $absence === null ? 0 : count($absence['detail']['absent_dates'] ?? []),
            'absence_deduction' => $fmt($sum('absence')),
            'penalties' => array_map(fn (array $d): array => [
                'id' => $d['source_id'],
                'penalty_date' => $d['detail']['penalty_date'] ?? null,
                'reason' => preg_replace('/^Penalty: /', '', (string) $d['description']),
                'deduction_amount' => $fmt((int) $d['amount_cents']),
            ], $of('penalty')),
            'penalty_deduction' => $fmt($sum('penalty')),
            'advances' => array_map(fn (array $d): array => [
                'id' => $d['source_id'],
                'advance_date' => $d['detail']['advance_date'] ?? null,
                'reason' => preg_replace('/^Salary advance: /', '', (string) $d['description']),
                'amount' => $fmt((int) $d['amount_cents']),
            ], $of('advance')),
            'advance_deduction' => $fmt($sum('advance')),
            'replacement_deduction' => $fmt((int) $line['replacement_deduction_cents']),
            'replacements' => array_map(fn (array $d): array => [...$d['detail'], 'description' => $d['description'], 'amount' => $fmt((int) $d['amount_cents'])], $of('replacement')),
            'unpaid_leave_deduction' => $fmt($sum('unpaid_leave')),
            'unpaid_leaves' => array_map(fn (array $d): array => [...$d['detail'], 'description' => $d['description'], 'amount' => $fmt((int) $d['amount_cents'])], $of('unpaid_leave')),
            'overpayment_recovery_deduction' => $fmt($sum('overpayment_recovery')),
            'overpayment_recoveries' => array_map(fn (array $d): array => [...$d['detail'], 'description' => $d['description'], 'amount' => $fmt((int) $d['amount_cents'])], $of('overpayment_recovery')),
            'adjustment' => $fmt((int) $line['adjustment_cents']),
            'net_payable' => $fmt((int) $line['net_cents']),
            'final_pay' => $line['calc_snapshot']['final_pay'] ?? null,
            'succession' => $line['calc_snapshot']['succession'] ?? null,
            ...self::provenance($view),
        ];
    }

    /**
     * An assignment that has no payroll line this month (Temporary employee,
     * cancelled, outside the month, unsupported salary): shown as not payable
     * with the reason, never as a made-up full-month figure.
     *
     * @param  array<string, mixed>  $view
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    public static function notPayable(int $employeeId, int $assignmentId, ?string $locationType, array $view, array $reasons): array
    {
        $zero = PayrollMoney::format(0);

        return [
            'employee_id' => $employeeId,
            'work_assignment_id' => $assignmentId,
            'payroll_period' => $view['period'],
            'payable' => false,
            'not_payable_reason' => $reasons === [] ? 'This assignment has no payroll line for this month (it is outside the assignment dates, cancelled, or belongs to a Temporary employee).' : implode(' ', $reasons),
            'location_type' => $locationType,
            'currency' => PayrollMoney::CURRENCY,
            'monthly_salary' => $zero, 'days_in_month' => (int) date('t', strtotime($view['period'].'-01')), 'eligible_days' => 0, 'daily_rate' => $zero,
            'gross_pay' => $zero, 'absent_days' => 0, 'absence_deduction' => $zero,
            'penalties' => [], 'penalty_deduction' => $zero, 'advances' => [], 'advance_deduction' => $zero,
            'replacement_deduction' => $zero, 'replacements' => [], 'unpaid_leave_deduction' => $zero, 'unpaid_leaves' => [],
            'overpayment_recovery_deduction' => $zero, 'overpayment_recoveries' => [],
            'adjustment' => $zero, 'net_payable' => $zero, 'final_pay' => null, 'succession' => null,
            ...self::provenance($view),
        ];
    }

    /**
     * @param  array<string, mixed>  $view
     * @return array<string, mixed>
     */
    private static function provenance(array $view): array
    {
        return [
            'source' => $view['source'],
            'is_estimate' => $view['is_estimate'],
            'estimate_note' => $view['is_estimate'] ? self::ESTIMATE_NOTE : null,
            'run_id' => $view['run_id'],
            'run_status' => $view['run_status'],
            'differs_from_run' => $view['differs_from_run'],
        ];
    }
}
