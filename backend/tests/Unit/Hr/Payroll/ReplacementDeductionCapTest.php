<?php

namespace Tests\Unit\Hr\Payroll;

use App\Support\Hr\Payroll\PayrollSheetBuilder;
use App\Support\Hr\Payroll\ReplacementDeductionCap;
use PHPUnit\Framework\TestCase;

/**
 * Owner decision 2026-11-03: Permanent net salary = max(0, gross - Temporary replacement deduction). Only what is taken off the Permanent is capped; the
 * Temporary's own rate (salary / 30) and payable amount (rate x days) are never changed. Synthetic data only.
 */
class ReplacementDeductionCapTest extends TestCase
{
    /** @return list<array{date: string, verified: bool}> */
    private function days(int $from, int $to, string $month = '2026-10'): array
    {
        $days = [];
        for ($d = $from; $d <= $to; $d++) {
            $days[] = ['date' => sprintf('%s-%02d', $month, $d), 'verified' => true];
        }

        return $days;
    }

    /** @return array<string, mixed> */
    private function permanent(array $replacements, array $deductions = [], int $salary = 30000, string $employedFrom = '2020-01-01'): array
    {
        return [
            'employee_id' => 1, 'name' => 'Perm One', 'company' => 'TEST COMPANY', 'workplace' => 'Test site',
            'salary_periods' => [['from' => '2020-01-01', 'monthly_cents' => $salary]],
            'employed_from' => $employedFrom, 'deductions' => $deductions, 'replacements' => $replacements,
        ];
    }

    /** @return array<string, mixed> */
    private function temp(int $id, string $start, array $workdays): array
    {
        return ['replacement_id' => $id * 10, 'employee_id' => $id, 'name' => "Temp {$id}", 'start_date' => $start, 'workdays' => $workdays, 'approved' => true];
    }

    /** @return array<string, mixed> */
    private function build(array $permanent): array
    {
        return (new PayrollSheetBuilder)->build('2026-10', [$permanent]);
    }

    public function test_a_deduction_below_the_gross_leaves_the_normal_net(): void
    {
        $sheet = $this->build($this->permanent([$this->temp(901, '2026-10-01', $this->days(1, 12))]));

        $this->assertSame(30000, $sheet['rows'][0]['gross_cents']);
        $this->assertSame(12000, $sheet['rows'][0]['replacement_deduction_cents'], '12 days x 300 / 30');
        $this->assertSame(18000, $sheet['rows'][0]['net_cents']);
        $this->assertSame(12000, $sheet['rows'][1]['amount_cents']);
        $this->assertSame(0, $sheet['reconciliation']['capped_cents']);
        $this->assertTrue($sheet['reconciliation']['ok']);
    }

    public function test_a_deduction_equal_to_the_gross_gives_a_net_of_exactly_zero(): void
    {
        $sheet = $this->build($this->permanent([$this->temp(901, '2026-10-01', $this->days(1, 30))]));

        $this->assertSame(30000, $sheet['rows'][0]['replacement_deduction_cents'], '30 days x 10.00');
        $this->assertSame(0, $sheet['rows'][0]['net_cents']);
        $this->assertSame(30000, $sheet['rows'][1]['amount_cents']);
        $this->assertSame(0, $sheet['reconciliation']['capped_cents']);
        $this->assertSame('', $sheet['rows'][0]['note']);
    }

    public function test_a_deduction_above_the_gross_is_capped_the_net_is_zero_and_the_temporary_is_still_owed_the_full_cost(): void
    {
        $sheet = $this->build($this->permanent([$this->temp(901, '2026-10-01', $this->days(1, 31))]));

        // Permanent gross 300.00; the cover costs 31 x (300 / 30) = 310.00.
        $this->assertSame(30000, $sheet['rows'][0]['gross_cents']);
        $this->assertSame(31000, $sheet['rows'][1]['amount_cents'], 'the Temporary payable amount is NOT capped: salary / 30 x workdays');
        $this->assertSame('10.0000', $sheet['rows'][1]['daily_rate']);
        $this->assertSame(30000, $sheet['rows'][0]['replacement_deduction_cents'], 'only 300.00 is deducted from the Permanent');
        $this->assertSame(0, $sheet['rows'][0]['net_cents'], 'never below zero');
        $this->assertSame(0, $sheet['totals']['net_cents']);
        $this->assertSame(1000, $sheet['reconciliation']['capped_cents']);
        $this->assertSame(31000, $sheet['reconciliation']['children_approved_cents']);
        $this->assertTrue($sheet['reconciliation']['ok'], 'the 10.00 not deducted is accounted for, not a mismatch');
        $this->assertStringNotContainsString('Negative net', $sheet['rows'][0]['note']);
    }

    public function test_several_covers_share_what_the_salary_has_left_in_order_and_each_temporary_keeps_their_full_amount(): void
    {
        $sheet = $this->build($this->permanent([
            $this->temp(901, '2026-10-01', $this->days(1, 20)), // 200.00
            $this->temp(902, '2026-10-21', $this->days(21, 31)), // 110.00
        ]));

        $this->assertSame([20000, 11000], [$sheet['rows'][1]['amount_cents'], $sheet['rows'][2]['amount_cents']], 'both Temporaries keep their full amount');
        $this->assertSame(30000, $sheet['rows'][0]['replacement_deduction_cents'], '200.00 + the 100.00 that is left');
        $this->assertSame(0, $sheet['rows'][0]['net_cents']);
        $this->assertSame(1000, $sheet['reconciliation']['capped_cents']);
    }

    public function test_the_other_deductions_are_not_changed_and_the_cover_only_takes_what_is_left(): void
    {
        // A 50.00 penalty first: only 250.00 is left for a 310.00 cover.
        $sheet = $this->build($this->permanent([$this->temp(901, '2026-10-01', $this->days(1, 31))], [['type' => 'Penalty', 'cents' => 5000]]));

        $this->assertSame(25000, $sheet['rows'][0]['replacement_deduction_cents']);
        $this->assertSame(5000, $sheet['totals']['other_deduction_cents'], 'the penalty is exactly what it was');
        $this->assertSame(0, $sheet['rows'][0]['net_cents']);
        $this->assertSame(6000, $sheet['reconciliation']['capped_cents']);
        $this->assertSame(31000, $sheet['rows'][1]['amount_cents']);
    }

    public function test_deductions_that_are_not_covers_keep_their_old_behaviour_even_when_they_exceed_the_gross(): void
    {
        // No Temporary cover is involved, so this rule is not: the existing flagged negative net is unchanged.
        $sheet = $this->build($this->permanent([], [['type' => 'Penalty', 'cents' => 40000]]));

        $this->assertSame(-10000, $sheet['rows'][0]['net_cents']);
        $this->assertStringContainsString('Negative net', $sheet['rows'][0]['note']);
    }

    public function test_the_permanents_own_proration_is_not_changed_by_the_cap(): void
    {
        // Employed from 16 October: 16 of 31 real days = 300.00 x 16 / 31 = 154.84 (rounded once), with or without a cover.
        $without = $this->build($this->permanent([], employedFrom: '2026-10-16'));
        $with = $this->build($this->permanent([$this->temp(901, '2026-10-16', $this->days(16, 31))], employedFrom: '2026-10-16'));

        $this->assertSame(15484, $without['rows'][0]['gross_cents']);
        $this->assertSame(15484, $with['rows'][0]['gross_cents']);
        $this->assertSame(16, $with['rows'][0]['eligible_days']);
        $this->assertSame(31, $with['rows'][0]['days_in_month']);
        $this->assertSame(16000, $with['rows'][1]['amount_cents'], '16 days x 10.00 - the Temporary divisor is 30, the Permanent proration is the real month');
        $this->assertSame(15484, $with['rows'][0]['replacement_deduction_cents'], 'capped at that gross');
        $this->assertSame(0, $with['rows'][0]['net_cents']);
    }

    public function test_the_cap_helper_itself(): void
    {
        $this->assertSame(30000, ReplacementDeductionCap::available(30000, 0));
        $this->assertSame(25000, ReplacementDeductionCap::available(30000, 5000));
        $this->assertSame(28000, ReplacementDeductionCap::available(30000, 5000, 3000), 'a pending adjustment gives back');
        $this->assertSame(0, ReplacementDeductionCap::available(30000, 40000), 'never negative');

        $this->assertSame([12000], ReplacementDeductionCap::allocate(30000, [12000]));
        $this->assertSame([30000], ReplacementDeductionCap::allocate(30000, [30000]));
        $this->assertSame([30000], ReplacementDeductionCap::allocate(30000, [31000]));
        $this->assertSame([20000, 10000], ReplacementDeductionCap::allocate(30000, [20000, 11000]));
        $this->assertSame([0, 0], ReplacementDeductionCap::allocate(0, [20000, 11000]));
        $this->assertSame([], ReplacementDeductionCap::allocate(30000, []));
    }
}
