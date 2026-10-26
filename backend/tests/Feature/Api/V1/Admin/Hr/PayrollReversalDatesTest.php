<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePayment;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollCorrection;
use App\Models\PayrollCorrectionTransaction;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The calendar day stamped on money rows that the SERVER dates itself (the default pay date of a payroll line, and the date of the reversal created by a void)
 * is the Mogadishu business day, like the dates the panel sends: between 21:00 and 24:00 UTC the UTC day is still yesterday in Mogadishu, and a reversal stamped with it
 * could be dated BEFORE the payment it reverses (even in the previous month). Timestamps (paid_at, voided_at) stay real UTC instants.
 */
class PayrollReversalDatesTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private ClientCompany $company;

    private Employee $employee;

    private EmployeeWorkAssignment $assignment;

    private Admin $superAdmin;

    private string $super;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-12-20 10:00:00');
        $this->company = $this->makeCompany('Acme Co');
        $this->employee = $this->makeEmployee();
        $this->employee->update(['department_id' => Department::query()->create(['name' => 'Cleaning Dept'])->id]);
        $this->assignment = $this->makeAssignment($this->employee, $this->company, null, 300, '2026-01-01');
        [$this->superAdmin, $this->super] = $this->superAdminToken();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** A finalized September line that is NOT paid yet (created at a fixed, unrelated moment). */
    private function finalizedLine(): PayrollItem
    {
        $id = $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])->assertCreated()->json('data.id');
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$id}/review")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$id}/approve")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$id}/finalize")->assertOk();

        return PayrollItem::query()->where('payroll_run_id', $id)->where('work_assignment_id', $this->assignment->id)->firstOrFail();
    }

    private function at(string $utc): void
    {
        CarbonImmutable::setTestNow($utc);
    }

    private function underpayment(int $cents): PayrollCorrection
    {
        $run = PayrollRun::query()->firstOrCreate(['period' => '2026-09'], ['status' => 'finalized']);

        return PayrollCorrection::query()->create([
            'batch_key' => (string) Str::uuid(), 'employee_id' => $this->employee->id, 'payroll_run_id' => $run->id, 'period' => '2026-09',
            'kind' => 'underpayment', 'action' => 'edited', 'recorded_net_cents' => 0, 'corrected_net_cents' => $cents, 'difference_cents' => -$cents,
            'outstanding_cents' => $cents, 'status' => 'open', 'reason' => 'earlier underpayment', 'corrected_by' => $this->superAdmin->id,
        ]);
    }

    /** @return array<string, array{string, string}> UTC instant => the Mogadishu calendar day it falls on */
    public static function instants(): array
    {
        return [
            'Mogadishu 23:59:59 on 31 Oct (20:59:59 UTC)' => ['2026-10-31 20:59:59', '2026-10-31'],
            'Mogadishu 00:00 on 1 Nov (21:00 UTC on 31 Oct)' => ['2026-10-31 21:00:00', '2026-11-01'],
            'Mogadishu 01:00 on 1 Nov (22:00 UTC on 31 Oct)' => ['2026-10-31 22:00:00', '2026-11-01'],
            'Mogadishu 02:59 on 1 Nov (23:59 UTC on 31 Oct)' => ['2026-10-31 23:59:00', '2026-11-01'],
            'Mogadishu 03:00 on 1 Nov (00:00 UTC, the calendars agree again)' => ['2026-11-01 00:00:00', '2026-11-01'],
            'an ordinary time of day (12:00 UTC)' => ['2026-11-01 12:00:00', '2026-11-01'],
            'a year boundary (22:30 UTC on 31 Dec)' => ['2026-12-31 22:30:00', '2027-01-01'],
        ];
    }

    #[DataProvider('instants')]
    public function test_a_line_paid_without_a_date_is_dated_with_the_mogadishu_day_and_paid_at_stays_a_utc_instant(string $utc, string $day): void
    {
        $item = $this->finalizedLine();
        $this->at($utc);

        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$item->id}/pay", [])->assertOk();

        $this->assertSame($day, EmployeePayment::query()->sole()->payment_date->toDateString());
        $this->assertSame($utc, $item->fresh()->paid_at->utc()->format('Y-m-d H:i:s'), 'the timestamp is not shifted');
    }

    public function test_a_date_the_panel_sends_is_stored_exactly_as_sent(): void
    {
        $item = $this->finalizedLine();
        $this->at('2026-10-31 22:00:00');

        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$item->id}/pay", ['payment_date' => '2026-10-30'])->assertOk();

        $this->assertSame('2026-10-30', EmployeePayment::query()->sole()->payment_date->toDateString());
    }

    #[DataProvider('instants')]
    public function test_the_reversal_of_a_payroll_payment_is_dated_with_the_mogadishu_day_and_never_before_its_original(string $utc, string $day): void
    {
        $item = $this->finalizedLine();
        $this->at($utc);
        // the panel sends the Mogadishu day of that moment
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$item->id}/pay", ['payment_date' => $day])->assertOk();

        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$item->id}/void-payment", ['reason' => 'Paid to the wrong account'])->assertOk();

        $original = EmployeePayment::query()->whereNull('reverses_payment_id')->sole();
        $reversal = EmployeePayment::query()->whereNotNull('reverses_payment_id')->sole();
        $this->assertSame($day, $reversal->payment_date->toDateString());
        $this->assertTrue($reversal->payment_date->greaterThanOrEqualTo($original->payment_date), 'a reversal is never dated before the payment it reverses');
    }

    #[DataProvider('instants')]
    public function test_the_reversal_of_an_underpayment_payment_is_dated_with_the_mogadishu_day_in_both_ledgers(string $utc, string $day): void
    {
        $correction = $this->underpayment(50000);
        $this->at($utc);
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions", [
            'type' => 'underpayment_payment', 'amount' => 100, 'transaction_date' => $day, 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
        $payment = PayrollCorrectionTransaction::query()->where('type', 'underpayment_payment')->sole();

        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions/{$payment->id}/void", ['reason' => 'Recorded for the wrong person'])->assertOk();

        $reversalTx = PayrollCorrectionTransaction::query()->where('type', 'underpayment_payment_reversal')->sole();
        $this->assertSame($day, $reversalTx->transaction_date->toDateString());
        $this->assertSame($day, EmployeePayment::query()->whereNotNull('reverses_payment_id')->sole()->payment_date->toDateString());
        $this->assertSame($utc, $reversalTx->created_at->utc()->format('Y-m-d H:i:s'), 'the row timestamp stays the real UTC instant');
    }

    public function test_the_month_boundary_case_is_the_one_that_used_to_land_in_the_previous_month(): void
    {
        // paid on 1 November (the Mogadishu day), voided one minute later at 01:01 Mogadishu = 22:01 UTC on 31 October
        $item = $this->finalizedLine();
        $this->at('2026-10-31 22:00:00');
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$item->id}/pay", ['payment_date' => '2026-11-01'])->assertOk();
        $this->at('2026-10-31 22:01:00');
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$item->id}/void-payment", ['reason' => 'Paid to the wrong account'])->assertOk();

        $reversal = EmployeePayment::query()->whereNotNull('reverses_payment_id')->sole();
        $this->assertSame('2026-11-01', $reversal->payment_date->toDateString(), 'it must not slide into October, before the payment it reverses');
    }
}
