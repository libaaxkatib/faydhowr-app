<?php

namespace App\Support\Hr\Payroll;

use RuntimeException;

/**
 * Builds the month-end payroll sheet as plain rows: one parent row per
 * Permanent employee, immediately followed by one child row per Temporary
 * replacement (chronological by replacement start date). Only parent rows
 * enter the payroll totals, so a replacement cost is never counted twice;
 * approved child amounts are deducted once from their own Permanent parent.
 */
final class PayrollSheetBuilder
{
    public function __construct(
        private readonly ProrationCalculator $proration = new ProrationCalculator,
        private readonly ReplacementCostCalculator $replacementCost = new ReplacementCostCalculator,
    ) {}

    /**
     * @param  list<array{employee_id: int|string, name: string, company: string, workplace?: ?string, status?: string, salary_periods: list<array{from: string, to?: ?string, monthly_cents: int}>, employed_from?: ?string, employed_to?: ?string, deductions?: list<array{type: string, cents: int}>, payroll_status?: string, replacements?: list<array{replacement_id: int|string, employee_id: int|string, name: string, start_date: string, end_date?: ?string, approved?: bool, workdays: list<array{date: string, verified: bool}>}>}>  $permanents
     * @return array{period: string, rows: list<array<string, mixed>>, totals: array<string, int>, reconciliation: array{children_approved_cents: int, parent_deductions_cents: int, ok: bool}}
     */
    public function build(string $period, array $permanents): array
    {
        $rows = [];
        $totals = ['gross_cents' => 0, 'replacement_deduction_cents' => 0, 'other_deduction_cents' => 0, 'net_cents' => 0];
        $childrenApproved = 0;

        foreach ($permanents as $permanent) {
            $salary = new SalaryTimeline($permanent['salary_periods']);
            $gross = $this->proration->monthlyGross($period, $salary, $permanent['employed_from'] ?? null, $permanent['employed_to'] ?? null);

            $children = [];
            $chargedBy = [];

            foreach ($permanent['replacements'] ?? [] as $replacement) {
                $cost = $this->replacementCost->monthlyCost($period, $salary, $replacement);

                foreach ($cost['charged_dates'] as $date) {
                    if (isset($chargedBy[$date])) {
                        throw new RuntimeException("Overlapping replacement charge on {$date} for employee {$permanent['employee_id']}: replacements {$chargedBy[$date]} and {$replacement['replacement_id']}.");
                    }
                    $chargedBy[$date] = $replacement['replacement_id'];
                }

                $approved = $replacement['approved'] ?? true;
                $children[] = [
                    'type' => 'temporary',
                    'employee_id' => $replacement['employee_id'],
                    'name' => $replacement['name'],
                    'company' => $permanent['company'],
                    'workplace' => $permanent['workplace'] ?? null,
                    'employment_type' => 'Temporary',
                    'period' => $period,
                    'parent_employee_id' => $permanent['employee_id'],
                    'replacement_id' => $replacement['replacement_id'],
                    'start_date' => $replacement['start_date'],
                    'end_date' => $replacement['end_date'] ?? null,
                    'verified_days' => $cost['verified_days'],
                    'daily_rate' => $cost['daily_rate'],
                    'amount_cents' => $cost['amount_cents'],
                    'deducted_from_parent' => $approved,
                    'note' => $approved ? '' : 'Pending approval - calculated but NOT deducted',
                ];
            }

            usort($children, fn (array $a, array $b): int => [$a['start_date'], (string) $a['replacement_id']] <=> [$b['start_date'], (string) $b['replacement_id']]);

            $replacementDeduction = array_sum(array_map(fn (array $c): int => $c['deducted_from_parent'] ? $c['amount_cents'] : 0, $children));
            $other = array_sum(array_column($permanent['deductions'] ?? [], 'cents'));
            $totalDeductions = $replacementDeduction + $other;
            $net = $gross['gross_cents'] - $totalDeductions;

            $rows[] = [
                'type' => 'permanent',
                'employee_id' => $permanent['employee_id'],
                'name' => $permanent['name'],
                'company' => $permanent['company'],
                'workplace' => $permanent['workplace'] ?? null,
                'status' => $permanent['status'] ?? 'Active',
                'employment_type' => 'Permanent',
                'period' => $period,
                'eligible_days' => $gross['eligible_days'],
                'days_in_month' => $gross['days_in_month'],
                'salary_segments' => $gross['segments'],
                'gross_cents' => $gross['gross_cents'],
                'replacement_deduction_cents' => $replacementDeduction,
                'other_deductions' => $permanent['deductions'] ?? [],
                'total_deductions_cents' => $totalDeductions,
                'net_cents' => $net,
                'payroll_status' => $permanent['payroll_status'] ?? 'Draft',
                'note' => $net < 0 ? 'Negative net payable - needs management decision' : '',
            ];

            foreach ($children as $child) {
                $rows[] = $child;
            }

            $totals['gross_cents'] += $gross['gross_cents'];
            $totals['replacement_deduction_cents'] += $replacementDeduction;
            $totals['other_deduction_cents'] += $other;
            $totals['net_cents'] += $net;
            $childrenApproved += $replacementDeduction;
        }

        return [
            'period' => $period,
            'rows' => $rows,
            'totals' => $totals,
            'reconciliation' => [
                'children_approved_cents' => $childrenApproved,
                'parent_deductions_cents' => $totals['replacement_deduction_cents'],
                'ok' => $childrenApproved === $totals['replacement_deduction_cents'],
            ],
        ];
    }
}
