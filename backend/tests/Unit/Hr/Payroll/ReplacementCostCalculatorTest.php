<?php

namespace Tests\Unit\Hr\Payroll;

use App\Support\Hr\Payroll\ReplacementCostCalculator;
use App\Support\Hr\Payroll\SalaryTimeline;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ReplacementCostCalculatorTest extends TestCase
{
    private function salary(int $cents = 30000): SalaryTimeline
    {
        return new SalaryTimeline([['from' => '2020-01-01', 'monthly_cents' => $cents]]);
    }

    /**
     * @return list<array{date: string, verified: bool}>
     */
    private function days(string $month, int $fromDay, int $toDay, bool $verified = true): array
    {
        $days = [];
        for ($d = $fromDay; $d <= $toDay; $d++) {
            $days[] = ['date' => sprintf('%s-%02d', $month, $d), 'verified' => $verified];
        }

        return $days;
    }

    public function test_thirty_day_month_example_gives_ten_dollars_a_day(): void
    {
        $cost = (new ReplacementCostCalculator)->monthlyCost('2026-11', $this->salary(), [
            'replacement_id' => 1, 'start_date' => '2026-11-01', 'workdays' => $this->days('2026-11', 3, 10),
        ]);

        $this->assertSame(8, $cost['verified_days']);
        $this->assertSame('10.0000', $cost['daily_rate']);
        $this->assertSame(8000, $cost['amount_cents']);
    }

    public function test_thirty_one_day_month_rounds_once_from_the_exact_fraction(): void
    {
        $cost = (new ReplacementCostCalculator)->monthlyCost('2026-10', $this->salary(), [
            'replacement_id' => 1, 'start_date' => '2026-10-01', 'workdays' => $this->days('2026-10', 3, 10),
        ]);

        $this->assertSame(7742, $cost['amount_cents']);
        $this->assertNotSame(7744, $cost['amount_cents'], 'A pre-rounded daily rate (9.68 x 8) must not be used.');
    }

    public function test_unverified_days_are_never_charged(): void
    {
        $workdays = [...$this->days('2026-11', 1, 5), ...$this->days('2026-11', 6, 8, false)];
        $cost = (new ReplacementCostCalculator)->monthlyCost('2026-11', $this->salary(), [
            'replacement_id' => 1, 'start_date' => '2026-11-01', 'workdays' => $workdays,
        ]);

        $this->assertSame(5, $cost['verified_days']);
        $this->assertSame(3, $cost['ignored']['unverified']);
        $this->assertSame(5000, $cost['amount_cents']);
    }

    public function test_days_before_the_start_and_after_the_end_are_not_charged(): void
    {
        $cost = (new ReplacementCostCalculator)->monthlyCost('2026-11', $this->salary(), [
            'replacement_id' => 1, 'start_date' => '2026-11-10', 'end_date' => '2026-11-12', 'workdays' => $this->days('2026-11', 8, 14),
        ]);

        $this->assertSame(['2026-11-10', '2026-11-11', '2026-11-12'], $cost['charged_dates']);
        $this->assertSame(4, $cost['ignored']['outside_window_or_month']);
        $this->assertSame(3000, $cost['amount_cents']);
    }

    public function test_the_same_date_listed_twice_is_charged_once(): void
    {
        $workdays = [...$this->days('2026-11', 3, 4), ['date' => '2026-11-03', 'verified' => true]];
        $cost = (new ReplacementCostCalculator)->monthlyCost('2026-11', $this->salary(), [
            'replacement_id' => 1, 'start_date' => '2026-11-01', 'workdays' => $workdays,
        ]);

        $this->assertSame(2, $cost['verified_days']);
        $this->assertSame(1, $cost['ignored']['duplicates']);
        $this->assertSame(2000, $cost['amount_cents']);
    }

    public function test_a_replacement_spanning_two_months_uses_each_months_own_denominator(): void
    {
        $replacement = [
            'replacement_id' => 1, 'start_date' => '2026-10-29',
            'workdays' => [...$this->days('2026-10', 29, 31), ...$this->days('2026-11', 1, 2)],
        ];
        $calc = new ReplacementCostCalculator;

        $october = $calc->monthlyCost('2026-10', $this->salary(), $replacement);
        $november = $calc->monthlyCost('2026-11', $this->salary(), $replacement);

        $this->assertSame(31, $october['days_in_month']);
        $this->assertSame(2903, $october['amount_cents']);
        $this->assertSame(30, $november['days_in_month']);
        $this->assertSame(2000, $november['amount_cents']);
    }

    public function test_the_salary_in_effect_on_each_worked_day_is_used(): void
    {
        $salary = new SalaryTimeline([
            ['from' => '2020-01-01', 'to' => '2026-10-15', 'monthly_cents' => 30000],
            ['from' => '2026-10-16', 'monthly_cents' => 40000],
        ]);
        $cost = (new ReplacementCostCalculator)->monthlyCost('2026-10', $salary, [
            'replacement_id' => 1, 'start_date' => '2026-10-01', 'workdays' => $this->days('2026-10', 14, 17),
        ]);

        $this->assertSame(4516, $cost['amount_cents']);
        $this->assertStringStartsWith('varies', $cost['daily_rate']);
    }

    public function test_a_worked_day_without_a_permanent_salary_is_never_guessed(): void
    {
        $salary = new SalaryTimeline([['from' => '2026-11-01', 'monthly_cents' => 30000]]);

        $this->expectException(RuntimeException::class);

        (new ReplacementCostCalculator)->monthlyCost('2026-10', $salary, [
            'replacement_id' => 1, 'start_date' => '2026-10-01', 'workdays' => $this->days('2026-10', 5, 6),
        ]);
    }

    public function test_no_workdays_means_no_cost(): void
    {
        $cost = (new ReplacementCostCalculator)->monthlyCost('2026-11', $this->salary(), [
            'replacement_id' => 1, 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'workdays' => [],
        ]);

        $this->assertSame(0, $cost['amount_cents']);
        $this->assertSame(0, $cost['verified_days']);
    }
}
