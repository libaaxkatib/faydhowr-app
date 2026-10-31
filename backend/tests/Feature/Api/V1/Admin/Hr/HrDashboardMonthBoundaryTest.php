<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * "This month's" payments, penalties and advances on the HR dashboard are the rows dated from the first day of the month (in) up to the first day of the
 * NEXT month (out). The old closed range "first day .. last day" lost anything dated on the last day whenever the date was stored with a time
 * ("2026-10-31 00:00:00" sorts after the plain string "2026-10-31"), so the totals were wrong on the last day of every month and could never
 * include a time of day on it.
 *
 * "Today" is moved with the test clock (never the system clock). Rows are inserted directly so that the stored text is exactly what is asserted on:
 * a plain date, a date with midnight, and a date with the last second of the day.
 */
class HrDashboardMonthBoundaryTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private int $employeeId;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        // Time is moved years ahead below; a token minted "now" would be past its 30 day life there. Only this test class turns the expiry off.
        config(['sanctum.expiration' => null]);
        $this->token = Admin::factory()->superAdmin()->create()->createToken('t')->plainTextToken;
        $this->employeeId = $this->makeEmployee('active')->id;
    }

    /** @param  array<int, array{0: string, 1: int|float}>  $rows  [stored date text, amount] */
    private function insert(array $rows): void
    {
        $now = now();
        $payments = $penalties = $advances = [];
        foreach ($rows as [$date, $amount]) {
            $payments[] = ['employee_id' => $this->employeeId, 'payment_date' => $date, 'amount' => $amount, 'currency' => 'USD', 'created_at' => $now, 'updated_at' => $now];
            $penalties[] = ['employee_id' => $this->employeeId, 'penalty_date' => $date, 'reason' => 'Test', 'deduction_amount' => $amount, 'currency' => 'USD', 'payroll_period' => '2026-10', 'created_at' => $now, 'updated_at' => $now];
            $advances[] = ['employee_id' => $this->employeeId, 'advance_date' => $date, 'amount' => $amount, 'currency' => 'USD', 'payroll_period' => '2026-10', 'reason' => 'Test', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($payments, 100) as $chunk) {
            DB::table('employee_payments')->insert($chunk);
        }
        foreach (array_chunk($penalties, 100) as $chunk) {
            DB::table('employee_penalties')->insert($chunk);
        }
        foreach (array_chunk($advances, 100) as $chunk) {
            DB::table('employee_advances')->insert($chunk);
        }
    }

    /** @return array{payments: float, penalties: float, advances: float} what the dashboard reports when "now" is the given UTC instant */
    private function totalsAt(string $now): array
    {
        $this->travelTo(CarbonImmutable::parse($now, 'UTC'));
        $this->app['auth']->forgetGuards();
        $data = $this->withToken($this->token)->getJson('/api/v1/admin/hr/dashboard')->assertOk()->json('data');

        return ['payments' => (float) $data['month_payments_total'], 'penalties' => (float) $data['month_penalties_total'], 'advances' => (float) $data['month_advances_total']];
    }

    private function assertAll(float $expected, string $now, string $message = ''): void
    {
        $this->assertSame(
            ['payments' => $expected, 'penalties' => $expected, 'advances' => $expected],
            $this->totalsAt($now),
            "at {$now} ".$message,
        );
    }

    public function test_the_first_day_the_last_day_a_time_on_the_last_day_and_the_neighbouring_months_are_each_counted_in_the_right_month(): void
    {
        // Every amount is a different power of two, so the total says exactly which rows were counted.
        $this->insert([
            ['2026-09-30', 1],                // the day before the month: out
            ['2026-09-30 23:59:59', 2],       // the last second of September: out
            ['2026-10-01', 4],                // first day: in
            ['2026-10-01 00:00:00', 8],       // the first instant: in
            ['2026-10-15', 16],               // middle: in
            ['2026-10-31', 32],               // the last day, plain date: in
            ['2026-10-31 00:00:00', 64],      // the last day stored with midnight: in (the old range lost this one)
            ['2026-10-31 23:59:59', 128],     // the last second of October: in
            ['2026-11-01', 256],              // the day after: out
            ['2026-11-01 00:00:00', 512],     // the first instant of November: out
        ]);

        $october = 4 + 8 + 16 + 32 + 64 + 128;
        // The answer is the same wherever "now" falls inside the month: its first instant, its middle, its very last second.
        foreach (['2026-10-01 00:00:00', '2026-10-15 12:00:00', '2026-10-31 00:00:00', '2026-10-31 23:59:59'] as $now) {
            $this->assertAll($october, $now, 'October holds exactly the rows dated 1-31 October');
        }
        $this->assertAll(256 + 512, '2026-11-01 00:00:00', 'the first instant of November starts November (and counts nothing of October)');
        $this->assertAll(1 + 2, '2026-09-30 23:59:59', 'September holds only its own rows');
    }

    /** @return array<string, array{0: int}> */
    public static function years(): array
    {
        return ['2027 (365 days, February has 28)' => [2027], '2028 (leap year, February has 29)' => [2028]];
    }

    /**
     * One transaction on every calendar day of the year, stored in the three shapes (plain date, midnight, last second) by turns. For every month, at
     * its first and at its very last instant, the dashboard reports exactly that month's days: so nothing is dropped and nothing is counted twice, in
     * 28, 29, 30 and 31 day months, across the New Year, and the rows of the neighbouring years never leak in.
     */
    #[DataProvider('years')]
    public function test_every_day_of_every_month_is_counted_once_in_months_of_every_length_and_in_a_leap_year(int $year): void
    {
        $rows = [[''.($year - 1).'-12-31', 1000], [''.($year - 1).'-12-31 23:59:59', 1000], [($year + 1).'-01-01', 1000], [($year + 1).'-01-01 00:00:00', 1000]];
        $day = CarbonImmutable::create($year, 1, 1);
        $daysInYear = 0;
        while ($day->year === $year) {
            $shape = match (true) {
                $day->day === $day->daysInMonth => $day->toDateString().' 23:59:59',
                $day->day % 2 === 0 => $day->toDateString().' 00:00:00',
                default => $day->toDateString(),
            };
            $rows[] = [$shape, 1];
            $day = $day->addDay();
            $daysInYear++;
        }
        $this->insert($rows);

        $sum = 0;
        foreach (range(1, 12) as $month) {
            $first = CarbonImmutable::create($year, $month, 1);
            $days = $first->daysInMonth;
            $sum += $days;

            $this->assertAll($days, $first->format('Y-m-d').' 00:00:00', "the first instant of {$first->format('F Y')} ({$days} days)");
            $this->assertAll($days, $first->endOfMonth()->format('Y-m-d H:i:s'), "the last instant of {$first->format('F Y')} ({$days} days)");
        }

        $this->assertSame($daysInYear, $sum, 'the twelve months add up to the year: every transaction is in exactly one month');
        $this->assertSame($year === 2028 ? 366 : 365, $daysInYear);
        $this->assertAll($year === 2028 ? 29 : 28, "{$year}-02-28 12:00:00", 'February has the right number of days');
    }

    public function test_a_month_without_transactions_reports_zero_and_does_not_borrow_from_its_neighbours(): void
    {
        $this->insert([['2026-10-31', 5], ['2026-12-01', 7]]);

        $this->assertAll(0, '2026-11-15 12:00:00', 'November has none');
        $this->assertAll(0, '2026-11-30 23:59:59', 'the last second of an empty month');
        $this->assertAll(5, '2026-10-31 23:59:59');
        $this->assertAll(7, '2026-12-01 00:00:00');
    }
}
