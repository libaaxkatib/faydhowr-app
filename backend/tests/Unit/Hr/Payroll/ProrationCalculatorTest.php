<?php

namespace Tests\Unit\Hr\Payroll;

use App\Support\Hr\Payroll\PayrollMoney;
use App\Support\Hr\Payroll\ProrationCalculator;
use App\Support\Hr\Payroll\SalaryTimeline;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProrationCalculatorTest extends TestCase
{
    private function flat(int $cents, string $from = '2020-01-01'): SalaryTimeline
    {
        return new SalaryTimeline([['from' => $from, 'monthly_cents' => $cents]]);
    }

    public function test_full_month_pays_the_whole_monthly_salary(): void
    {
        $result = (new ProrationCalculator)->monthlyGross('2026-10', $this->flat(30000), '2026-09-01');

        $this->assertSame(31, $result['eligible_days']);
        $this->assertSame(30000, $result['gross_cents']);
    }

    public function test_mid_month_start_matches_the_approved_example(): void
    {
        $result = (new ProrationCalculator)->monthlyGross('2026-10', $this->flat(30000), '2026-10-11');

        $this->assertSame(21, $result['eligible_days']);
        $this->assertSame(31, $result['days_in_month']);
        $this->assertSame('203.23', PayrollMoney::format($result['gross_cents']));
    }

    public function test_start_on_the_first_day_is_a_full_month(): void
    {
        $result = (new ProrationCalculator)->monthlyGross('2026-10', $this->flat(30000), '2026-10-01');

        $this->assertSame(31, $result['eligible_days']);
        $this->assertSame(30000, $result['gross_cents']);
    }

    public function test_start_on_the_last_day_is_one_day(): void
    {
        $result = (new ProrationCalculator)->monthlyGross('2026-10', $this->flat(30000), '2026-10-31');

        $this->assertSame(1, $result['eligible_days']);
        $this->assertSame(968, $result['gross_cents']);
    }

    public function test_february_in_an_ordinary_year_has_28_days(): void
    {
        $calc = new ProrationCalculator;

        $this->assertSame(28, $calc->monthlyGross('2027-02', $this->flat(28000), null)['days_in_month']);
        $this->assertSame(14000, $calc->monthlyGross('2027-02', $this->flat(28000), '2027-02-15')['gross_cents']);
    }

    public function test_february_in_a_leap_year_has_29_days(): void
    {
        $calc = new ProrationCalculator;

        $this->assertSame(29, $calc->monthlyGross('2028-02', $this->flat(29000), null)['days_in_month']);
        $this->assertSame(15000, $calc->monthlyGross('2028-02', $this->flat(29000), '2028-02-15')['gross_cents']);
    }

    public function test_a_30_day_month_uses_30_as_the_denominator(): void
    {
        $result = (new ProrationCalculator)->monthlyGross('2026-11', $this->flat(30000), '2026-11-11');

        $this->assertSame(30, $result['days_in_month']);
        $this->assertSame(20, $result['eligible_days']);
        $this->assertSame(20000, $result['gross_cents']);
    }

    public function test_employment_end_date_is_inclusive(): void
    {
        $result = (new ProrationCalculator)->monthlyGross('2026-10', $this->flat(30000), '2026-09-01', '2026-10-10');

        $this->assertSame(10, $result['eligible_days']);
        $this->assertSame(9677, $result['gross_cents']);
    }

    public function test_start_and_end_inside_the_same_month(): void
    {
        $result = (new ProrationCalculator)->monthlyGross('2026-10', $this->flat(31000), '2026-10-11', '2026-10-20');

        $this->assertSame(10, $result['eligible_days']);
        $this->assertSame(10000, $result['gross_cents']);
    }

    public function test_an_employee_not_yet_employed_earns_nothing(): void
    {
        $result = (new ProrationCalculator)->monthlyGross('2026-10', $this->flat(30000), '2026-11-01');

        $this->assertSame(0, $result['eligible_days']);
        $this->assertSame(0, $result['gross_cents']);
    }

    public function test_a_mid_month_salary_change_is_split_by_calendar_days(): void
    {
        $salary = new SalaryTimeline([
            ['from' => '2020-01-01', 'to' => '2026-10-15', 'monthly_cents' => 30000],
            ['from' => '2026-10-16', 'monthly_cents' => 40000],
        ]);

        $result = (new ProrationCalculator)->monthlyGross('2026-10', $salary, '2020-01-01');

        $this->assertSame(31, $result['eligible_days']);
        $this->assertSame(35161, $result['gross_cents']);
        $this->assertCount(2, $result['segments']);
        $this->assertSame(15, $result['segments'][0]['days']);
        $this->assertSame(16, $result['segments'][1]['days']);
    }

    public function test_overlapping_salary_periods_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SalaryTimeline([
            ['from' => '2026-01-01', 'to' => '2026-06-30', 'monthly_cents' => 30000],
            ['from' => '2026-06-30', 'monthly_cents' => 40000],
        ]);
    }

    public function test_an_open_ended_period_followed_by_another_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SalaryTimeline([
            ['from' => '2026-01-01', 'monthly_cents' => 30000],
            ['from' => '2026-06-01', 'monthly_cents' => 40000],
        ]);
    }

    public function test_an_eligible_day_without_a_salary_is_never_guessed(): void
    {
        $salary = new SalaryTimeline([['from' => '2026-10-10', 'monthly_cents' => 30000]]);

        $this->expectException(RuntimeException::class);

        (new ProrationCalculator)->monthlyGross('2026-10', $salary, '2026-10-01');
    }

    public function test_an_invalid_period_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProrationCalculator)->monthlyGross('2026-13', $this->flat(30000), null);
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function roundingCases(): array
    {
        return [
            'exact half rounds up' => [5, 2, 3],
            'exact whole stays' => [4, 2, 2],
            'one half' => [1, 2, 1],
            'below half rounds down' => [7, 3, 2],
            'above half rounds up' => [8, 3, 3],
            'zero' => [0, 5, 0],
            'negative half rounds away from zero' => [-5, 2, -3],
        ];
    }

    #[DataProvider('roundingCases')]
    public function test_round_half_up_rule(int $numerator, int $denominator, int $expected): void
    {
        $this->assertSame($expected, PayrollMoney::roundDiv($numerator, $denominator));
    }

    public function test_money_formatting(): void
    {
        $this->assertSame('203.23', PayrollMoney::format(20323));
        $this->assertSame('0.05', PayrollMoney::format(5));
        $this->assertSame('-0.05', PayrollMoney::format(-5));
        $this->assertSame(30050, PayrollMoney::toCents('300.50'));
    }
}
