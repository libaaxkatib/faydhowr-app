<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\EmployeePayment;
use App\Models\PayrollCorrection;
use App\Models\PayrollCorrectionTransaction;
use App\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * F-DATE: the date of a payroll settlement (a repayment, the payment of an underpayment, a write-off) is a calendar day in the business calendar,
 * Africa/Mogadishu (UTC+3). The server used to compare it with the UTC day, so between 00:00 and 03:00 in Mogadishu - when the browser (which
 * proposes the Mogadishu day) is already one day ahead of UTC - it refused today's date as "in the future". The server is still strict: a day that
 * is genuinely in the future in Mogadishu is refused, and the date is stored as the plain calendar day that was sent.
 */
class PayrollSettlementDateTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private Admin $superAdmin;

    private string $super;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->superAdmin, $this->super] = $this->superAdminToken();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function correction(string $kind): PayrollCorrection
    {
        $employee = $this->makeEmployee('inactive');
        $run = PayrollRun::query()->firstOrCreate(['period' => '2026-09'], ['status' => 'finalized']);
        $over = $kind === 'overpayment';

        return PayrollCorrection::query()->create([
            'batch_key' => (string) Str::uuid(), 'employee_id' => $employee->id, 'payroll_run_id' => $run->id, 'period' => '2026-09',
            'kind' => $kind, 'action' => 'edited', 'recorded_net_cents' => $over ? 50000 : 0, 'corrected_net_cents' => $over ? 0 : 50000,
            'difference_cents' => $over ? 50000 : -50000, 'outstanding_cents' => 50000, 'status' => 'open', 'reason' => 'earlier correction', 'corrected_by' => $this->superAdmin->id,
        ]);
    }

    /** The three dialogs of the ledger: the repayment of a debt, the payment of an underpayment and a write-off. */
    private function settle(string $type, string $date)
    {
        $correction = $this->correction($type === 'underpayment_payment' ? 'underpayment' : 'overpayment');
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->super)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions", [
            'type' => $type, 'amount' => 10, 'transaction_date' => $date, 'idempotency_key' => (string) Str::uuid(),
            'reason' => $type === 'debt_write_off' ? 'The employee cannot repay' : null,
        ]);
    }

    /** @return array<string, array{string}> */
    public static function types(): array
    {
        return ['repayment' => ['debt_repayment'], 'payment of an underpayment' => ['underpayment_payment'], 'write-off' => ['debt_write_off']];
    }

    /**
     * Each row: the UTC instant, then the dates that must be accepted and the dates that must be refused.
     *
     * @return array<string, array{string, list<string>, list<string>}>
     */
    public static function instants(): array
    {
        return [
            // Mogadishu midnight is 21:00 UTC of the day before: from here to 02:59 Mogadishu the Mogadishu day is ONE AHEAD of the UTC day.
            'Mogadishu 00:00 on 26 Oct (21:00 UTC on 25 Oct)' => ['2026-10-25 21:00:00', ['2026-10-26', '2026-10-25'], ['2026-10-27']],
            'Mogadishu 02:59 on 26 Oct (23:59 UTC on 25 Oct)' => ['2026-10-25 23:59:00', ['2026-10-26', '2026-10-25'], ['2026-10-27']],
            // One second before Mogadishu midnight it is still 25 October there: 26 October is genuinely in the future.
            'Mogadishu 23:59:59 on 25 Oct (20:59:59 UTC)' => ['2026-10-25 20:59:59', ['2026-10-25', '2026-10-24'], ['2026-10-26', '2026-10-27']],
            // Outside the window, the two calendars agree.
            'Mogadishu 15:00 on 25 Oct (12:00 UTC)' => ['2026-10-25 12:00:00', ['2026-10-25'], ['2026-10-26']],
            'Mogadishu 03:00 on 26 Oct (00:00 UTC, the window is over)' => ['2026-10-26 00:00:00', ['2026-10-26'], ['2026-10-27']],
            // A month boundary: 1 November in Mogadishu while it is still 31 October in UTC.
            'Mogadishu 01:00 on 1 Nov (22:00 UTC on 31 Oct)' => ['2026-10-31 22:00:00', ['2026-11-01', '2026-10-31'], ['2026-11-02']],
        ];
    }

    #[DataProvider('types')]
    public function test_every_instant_around_the_mogadishu_midnight_is_judged_by_the_mogadishu_day(string $type): void
    {
        foreach (self::instants() as $label => [$now, $accepted, $refused]) {
            CarbonImmutable::setTestNow($now);

            foreach ($accepted as $date) {
                $this->settle($type, $date)->assertCreated();
            }

            foreach ($refused as $date) {
                $this->settle($type, $date)->assertUnprocessable()->assertJsonValidationErrors('transaction_date', 'errors');
            }

            $stored = PayrollCorrectionTransaction::query()->where('type', $type)->get()->map(fn ($t) => $t->transaction_date->toDateString())->sort()->values()->all();
            $expected = $accepted;
            sort($expected);
            $this->assertSame($expected, $stored, $label);
            PayrollCorrectionTransaction::query()->delete();
        }
    }

    #[DataProvider('types')]
    public function test_a_refused_date_records_nothing(string $type): void
    {
        CarbonImmutable::setTestNow('2026-10-25 20:59:59');

        $this->settle($type, '2026-10-26')->assertUnprocessable();

        $this->assertSame(0, PayrollCorrectionTransaction::query()->count());
        $this->assertSame(0, EmployeePayment::query()->count());
    }

    #[DataProvider('types')]
    public function test_the_date_is_stored_as_the_plain_calendar_day_that_was_sent_and_never_slides(string $type): void
    {
        CarbonImmutable::setTestNow('2026-10-25 21:30:00');

        $this->settle($type, '2026-10-26')->assertCreated();

        $transaction = PayrollCorrectionTransaction::query()->sole();
        $this->assertSame('2026-10-26', $transaction->transaction_date->toDateString());
        $this->assertSame('2026-10-26', substr((string) DB::table('payroll_correction_transactions')->value('transaction_date'), 0, 10));

        if ($type === 'underpayment_payment') {
            $this->assertSame('2026-10-26', EmployeePayment::query()->sole()->payment_date->toDateString());
        }
    }

    public function test_a_value_that_is_not_a_date_is_still_refused(): void
    {
        CarbonImmutable::setTestNow('2026-10-25 21:30:00');

        $this->settle('debt_repayment', 'tomorrow-ish')->assertUnprocessable();
        $this->settle('debt_repayment', '')->assertUnprocessable();
        $this->assertSame(0, PayrollCorrectionTransaction::query()->count());
    }
}
