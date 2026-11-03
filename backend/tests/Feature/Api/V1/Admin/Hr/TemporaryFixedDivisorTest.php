<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItemDeduction;
use App\Models\TemporaryReplacement;
use App\Support\Hr\Payroll\TemporaryRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decision 2026-11-03: the Temporary daily rate ALWAYS uses a fixed divisor of 30, whatever the month:
 *
 *   Temporary daily rate    = Permanent monthly salary / 30
 *   Temporary payable amount = daily rate x covered days
 *
 * One definition (Payroll\TemporaryRate) feeds the Temporary's expected payment, the cost deducted from the covered Permanent, the payroll run and
 * the payment list, so they can never disagree. The Permanent's OWN proration (monthly salary / real days of the month) is a different rule and is
 * not touched. Synthetic data only.
 */
class TemporaryFixedDivisorTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2027-03-10 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: EmployeeWorkAssignment, 1: Employee} */
    private function position(int $dollars): array
    {
        $permanent = $this->makeEmployee('active', 'permanent');

        return [$this->makeAssignment($permanent, $this->makeCompany(fake()->unique()->company()), null, $dollars, '2026-01-01'), $this->makeEmployee('active', 'temporary')];
    }

    private function cover(EmployeeWorkAssignment $a, Employee $temp, string $start, string $end, bool $approved = false): TemporaryReplacement
    {
        return TemporaryReplacement::query()->create([
            'work_assignment_id' => $a->id, 'replacement_employee_id' => $temp->id, 'start_date' => $start, 'end_date' => $end,
            'currency' => 'USD', 'status' => 'ended', 'approved_at' => $approved ? now() : null,
        ]);
    }

    /** @return array<string, array{int, string, string, string, string}> salary, first day, last day, expected pay, expected daily rate */
    public static function approvedTable(): array
    {
        return [
            '$300 x 12 days (30-day month)' => [300, '2026-09-01', '2026-09-12', '120.00', '10.00'],
            '$600 x 15 days' => [600, '2026-09-01', '2026-09-15', '300.00', '20.00'],
            '$450 x 10 days' => [450, '2026-09-01', '2026-09-10', '150.00', '15.00'],
            '$300 x 28 days (the whole of February 2027)' => [300, '2027-02-01', '2027-02-28', '280.00', '10.00'],
            '$300 x 31 days (the whole of October 2026)' => [300, '2026-10-01', '2026-10-31', '310.00', '10.00'],
            '$300 x 12 days in a 31-day month' => [300, '2026-10-01', '2026-10-12', '120.00', '10.00'],
            '$300 x 12 days in February' => [300, '2027-02-01', '2027-02-12', '120.00', '10.00'],
            '$300 x 12 days in a leap February' => [300, '2028-02-01', '2028-02-12', '120.00', '10.00'],
        ];
    }

    #[DataProvider('approvedTable')]
    public function test_the_temporary_is_paid_the_permanents_salary_over_30_times_the_covered_days(int $salary, string $from, string $to, string $pay, string $rate): void
    {
        CarbonImmutable::setTestNow('2028-06-01 10:00:00');
        [, $token] = $this->superAdminToken(); // a token minted before the clock moved would already have expired
        [$a, $temp] = $this->position($salary);
        $r = $this->cover($a, $temp, $from, $to);
        $period = substr($from, 0, 7);

        // What the Temporary is expected to receive.
        $this->withToken($token)->getJson("/api/v1/admin/hr/temporary-replacements/{$r->id}/expected-payment")->assertOk()
            ->assertJsonPath('data.expected', $pay)
            ->assertJsonPath('data.months.0.rate_divisor', 30);

        // What the covered Permanent is charged for the same days: the same figure, from the same definition.
        $this->withToken($token)->getJson("/api/v1/admin/hr/temporary-replacements/{$r->id}/cost?period={$period}")->assertOk()
            ->assertJsonPath('data.amount', $pay)
            ->assertJsonPath('data.entitlement_cents', (int) round((float) $pay * 100))
            ->assertJsonPath('data.rate_divisor', 30);
        $this->assertSame($rate, number_format((float) $this->withToken($token)->getJson("/api/v1/admin/hr/temporary-replacements/{$r->id}/cost?period={$period}")->json('data.daily_rate'), 2, '.', ''));
    }

    public function test_the_payroll_run_deducts_exactly_what_the_temporary_is_paid(): void
    {
        CarbonImmutable::setTestNow('2026-11-20 10:00:00');
        [, $token] = $this->superAdminToken();
        [$a, $temp] = $this->position(300);
        $this->cover($a, $temp, '2026-10-01', '2026-10-12', approved: true); // 12 days of a 31-day month

        $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-10'])->assertCreated();

        $deduction = PayrollItemDeduction::query()->where('kind', 'replacement')->sole();
        $this->assertSame(12000, (int) $deduction->amount_cents, '300 / 30 = 10.00 a day x 12 days = 120.00 (not 300 / 31 x 12 = 116.13)');
        // The Permanent's own pay is untouched by this rule: a whole 31-day month is still the monthly salary.
        $this->assertSame(30000, (int) $deduction->item->gross_cents);
    }

    public function test_the_divisor_is_defined_once_and_nothing_else_divides_a_temporary_amount_by_the_real_month_length(): void
    {
        $this->assertSame(30, TemporaryRate::DIVISOR);

        foreach (['ReplacementCostCalculator', 'ReplacementCostService', 'TemporaryExpectedPayment'] as $file) {
            $source = file_get_contents(base_path("app/Support/Hr/Payroll/{$file}.php"));
            $this->assertStringContainsString('TemporaryRate::', $source, "{$file} must use the one definition");
            $this->assertDoesNotMatchRegularExpression('/roundDiv\([^;]*\$daysInMonth\)|roundDiv\([^;]*\[\'days_in_month\'\]\)/', $source, "{$file} still divides a Temporary amount by the real month length");
        }
    }
}
