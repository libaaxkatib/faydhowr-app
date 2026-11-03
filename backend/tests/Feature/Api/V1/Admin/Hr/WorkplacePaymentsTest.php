<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\EmployeePayment;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PayrollWorkplacePayment;
use App\Models\WorkLocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Payments per WORKPLACE for a payroll month: full and partial payments (several per workplace), the total / paid / outstanding / status of each
 * workplace on its own, the payment history, and the guarantees around them: only a finalized month, never above the outstanding balance, one
 * workplace never touching another, retries recorded once, mistakes voided with a reason (role-checked), and payroll lines, salaries and assignments
 * never changed. Synthetic data only.
 *
 * September 2026 (30 days), everybody assigned for the whole month, so a line is exactly the monthly salary:
 *   Acme Co      : two employees, 300 + 200 = 500.00
 *   Beta Co      : one employee, 400.00
 *   Gamma (guri) : one employee, 250.00
 *   Delta Co     : two work locations, "North" 120.00 and "South" 180.00 (each its own workplace)
 */
class WorkplacePaymentsTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private ClientCompany $acme;

    private ClientCompany $beta;

    private ClientCompany $gamma;

    private ClientCompany $delta;

    private WorkLocation $north;

    private WorkLocation $south;

    private string $super;

    private int $runId;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-12-20 10:00:00');
        [, $this->super] = $this->superAdminToken();

        $this->acme = $this->makeCompany('Acme Co');
        $this->beta = $this->makeCompany('Beta Co');
        $this->gamma = $this->makeCompany('Gamma Guri', 'guri');
        $this->delta = $this->makeCompany('Delta Co');
        $this->north = $this->makeClientLocation($this->delta, 'North');
        $this->south = $this->makeClientLocation($this->delta, 'South');

        $this->makeAssignment($this->makeEmployee(), $this->acme, null, 300);
        $this->makeAssignment($this->makeEmployee(), $this->acme, null, 200);
        $this->makeAssignment($this->makeEmployee(), $this->beta, null, 400);
        $this->makeAssignment($this->makeEmployee(), $this->gamma, null, 250);
        $this->makeAssignment($this->makeEmployee(), $this->delta, $this->north, 120);
        $this->makeAssignment($this->makeEmployee(), $this->delta, $this->south, 180);
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

    private function draft(): int
    {
        return $this->runId = (int) $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])->assertCreated()->json('data.id');
    }

    private function finalized(): int
    {
        $id = $this->draft();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$id}/review")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$id}/approve")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$id}/finalize")->assertOk();

        return $id;
    }

    /** @return array<string, array<string, mixed>> the workplaces of the month by name ("Acme Co", "Delta Co / North") */
    private function workplaces(?string $token = null): array
    {
        $rows = $this->as($token ?? $this->super)->getJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/workplace-payments")->assertOk()->json('data.workplaces');
        $named = [];

        foreach ($rows as $row) {
            $named[$row['company_name'].($row['workplace_name'] ? ' / '.$row['workplace_name'] : '')] = $row;
        }

        return $named;
    }

    /** @param array<string, mixed> $workplace */
    private function pay(array $workplace, array $body = [], ?string $token = null)
    {
        return $this->as($token ?? $this->super)->postJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/workplace-payments", $body + [
            'client_company_id' => $workplace['client_company_id'],
            'work_location_id' => $workplace['work_location_id'],
            'mode' => 'full',
            'payment_date' => '2026-12-18',
            'idempotency_key' => 'key-'.bin2hex(random_bytes(6)),
        ]);
    }

    /** @return array<string, mixed> every figure that must never move because of a workplace payment */
    private function payrollFingerprint(): array
    {
        return [
            'items' => PayrollItem::query()->orderBy('id')->get(['id', 'employee_id', 'work_assignment_id', 'gross_cents', 'net_cents', 'payment_status', 'paid_at', 'employee_payment_id', 'calc_snapshot'])->toArray(),
            'run' => PayrollRun::query()->orderBy('id')->get()->toArray(),
            'assignments' => EmployeeWorkAssignment::query()->orderBy('id')->get()->toArray(),
            'employee_payments' => EmployeePayment::query()->count(),
        ];
    }

    public function test_every_workplace_has_its_own_total_paid_outstanding_and_status(): void
    {
        $this->finalized();
        $w = $this->workplaces();

        $this->assertSame(['Acme Co', 'Beta Co', 'Delta Co / North', 'Delta Co / South', 'Gamma Guri'], array_keys($w));
        $this->assertSame([50000, 40000, 12000, 18000, 25000], array_column(array_values($w), 'total_cents'));
        $this->assertSame(['unpaid'], array_values(array_unique(array_column($w, 'status'))));
        $this->assertSame('500.00', $w['Acme Co']['outstanding']);
        $this->assertSame('0.00', $w['Acme Co']['paid']);
        $this->assertSame(2, $w['Acme Co']['employee_count']);
        $this->assertSame('guri', $w['Gamma Guri']['category']);
        $this->assertSame('company', $w['Acme Co']['category']);

        $totals = $this->as($this->super)->getJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/workplace-payments")->json('data.totals');
        $this->assertSame(['total' => '1450.00', 'paid' => '0.00', 'outstanding' => '1450.00'], $totals);
    }

    public function test_a_full_payment_pays_the_whole_outstanding_balance_of_that_workplace_only(): void
    {
        $this->finalized();
        $before = $this->payrollFingerprint();
        $acme = $this->workplaces()['Acme Co'];

        $response = $this->pay($acme, ['mode' => 'full'])->assertCreated();
        $response->assertJsonPath('data.workplace.status', 'paid')->assertJsonPath('data.workplace.outstanding', '0.00')->assertJsonPath('data.workplace.paid', '500.00');

        $w = $this->workplaces();
        $this->assertSame('paid', $w['Acme Co']['status']);
        // The others are untouched: paying one workplace never marks another paid.
        foreach (['Beta Co', 'Delta Co / North', 'Delta Co / South', 'Gamma Guri'] as $name) {
            $this->assertSame('unpaid', $w[$name]['status'], $name);
            $this->assertSame(0, $w[$name]['paid_cents'], $name);
            $this->assertSame($w[$name]['total_cents'], $w[$name]['outstanding_cents'], $name);
        }

        $this->assertSame($before, $this->payrollFingerprint(), 'payroll lines, run, assignments and the employee payment ledger are not changed by a workplace payment');
    }

    public function test_partial_payments_can_be_recorded_several_times_and_the_status_follows_the_balance(): void
    {
        $this->finalized();
        $beta = $this->workplaces()['Beta Co'];

        $this->pay($beta, ['mode' => 'partial', 'amount' => '100', 'payment_date' => '2026-12-01'])->assertCreated()->assertJsonPath('data.workplace.status', 'partial');
        $this->assertSame(['partial', '300.00', '100.00'], $this->snapshot('Beta Co'));

        $this->pay($beta, ['mode' => 'partial', 'amount' => '150.50', 'payment_date' => '2026-12-10'])->assertCreated()->assertJsonPath('data.workplace.status', 'partial');
        $this->assertSame(['partial', '149.50', '250.50'], $this->snapshot('Beta Co'));

        // The remaining balance exactly: the workplace becomes PAID by itself.
        $this->pay($beta, ['mode' => 'partial', 'amount' => '149.50', 'payment_date' => '2026-12-18'])->assertCreated()->assertJsonPath('data.workplace.status', 'paid');
        $this->assertSame(['paid', '0.00', '400.00'], $this->snapshot('Beta Co'));

        $history = $this->workplaces()['Beta Co']['payments'];
        $this->assertSame(['100.00', '150.50', '149.50'], array_column($history, 'amount'));
        $this->assertSame(['2026-12-01', '2026-12-10', '2026-12-18'], array_column($history, 'payment_date'));
        $this->assertSame([false, false, false], array_column($history, 'voided'));
        $this->assertNotEmpty($history[0]['recorded_by']);
        $this->assertSame('unpaid', $this->workplaces()['Acme Co']['status']);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function snapshot(string $name): array
    {
        $w = $this->workplaces()[$name];

        return [$w['status'], $w['outstanding'], $w['paid']];
    }

    public function test_a_payment_can_never_exceed_the_outstanding_balance_or_be_empty_or_dated_in_the_future(): void
    {
        $this->finalized();
        $beta = $this->workplaces()['Beta Co'];

        $this->pay($beta, ['mode' => 'partial', 'amount' => '400.01'])->assertStatus(422)->assertJsonPath('error_code', 'AMOUNT_EXCEEDS_OUTSTANDING');
        $this->pay($beta, ['mode' => 'partial', 'amount' => '0'])->assertStatus(422);
        $this->pay($beta, ['mode' => 'partial', 'amount' => '-5'])->assertStatus(422);
        $this->pay($beta, ['mode' => 'partial'])->assertStatus(422);
        $this->pay($beta, ['mode' => 'partial', 'amount' => '10.123'])->assertStatus(422);
        $this->pay($beta, ['mode' => 'partial', 'amount' => '10', 'payment_date' => '2026-12-21'])->assertStatus(422)->assertJsonPath('error_code', 'PAYMENT_DATE_IN_FUTURE');
        $this->pay($beta, ['mode' => 'nonsense'])->assertStatus(422);
        $this->assertSame(0, PayrollWorkplacePayment::query()->count());

        // Today's own date (the Mogadishu day) is allowed: a payment made today is recorded today.
        $this->pay($beta, ['mode' => 'partial', 'amount' => '1', 'payment_date' => '2026-12-20'])->assertCreated();
        $this->assertSame(1, PayrollWorkplacePayment::query()->count());
        PayrollWorkplacePayment::query()->delete();

        $this->pay($beta, ['mode' => 'full'])->assertCreated();
        $this->pay($beta, ['mode' => 'full'])->assertStatus(422)->assertJsonPath('error_code', 'WORKPLACE_NOTHING_OUTSTANDING');
        $this->pay($beta, ['mode' => 'partial', 'amount' => '1'])->assertStatus(422)->assertJsonPath('error_code', 'WORKPLACE_NOTHING_OUTSTANDING');
        $this->assertSame(1, PayrollWorkplacePayment::query()->count());
    }

    public function test_only_a_finalized_month_can_be_paid_and_a_workplace_must_be_in_the_payroll(): void
    {
        $this->draft();
        $this->assertFalse($this->as($this->super)->getJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/workplace-payments")->json('data.can_record'));
        $acme = $this->workplaces()['Acme Co'];
        $this->pay($acme)->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_NOT_FINALIZED');

        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/review")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/approve")->assertOk();
        $this->pay($acme)->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_NOT_FINALIZED');
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/finalize")->assertOk();
        $this->assertTrue($this->as($this->super)->getJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/workplace-payments")->json('data.can_record'));

        $other = $this->makeCompany('Nobody Co');
        $this->pay(['client_company_id' => $other->id, 'work_location_id' => null])->assertStatus(422)->assertJsonPath('error_code', 'WORKPLACE_NOT_IN_PAYROLL');
        $this->pay(['client_company_id' => null, 'work_location_id' => null])->assertStatus(422);
        $this->pay(['client_company_id' => 999999, 'work_location_id' => null])->assertStatus(422);
        $this->assertSame(0, PayrollWorkplacePayment::query()->count());
    }

    public function test_the_locations_of_one_company_are_separate_workplaces(): void
    {
        $this->finalized();
        $w = $this->workplaces();

        $this->pay($w['Delta Co / North'], ['mode' => 'full'])->assertCreated();
        $this->pay($w['Delta Co / South'], ['mode' => 'partial', 'amount' => '50'])->assertCreated();

        $w = $this->workplaces();
        $this->assertSame(['paid', 'partial'], [$w['Delta Co / North']['status'], $w['Delta Co / South']['status']]);
        $this->assertSame(['0.00', '130.00'], [$w['Delta Co / North']['outstanding'], $w['Delta Co / South']['outstanding']]);
        // North's full payment cannot be used to pay South: a payment cannot exceed that workplace's own balance.
        $this->pay($w['Delta Co / South'], ['mode' => 'partial', 'amount' => '130.01'])->assertStatus(422)->assertJsonPath('error_code', 'AMOUNT_EXCEEDS_OUTSTANDING');
    }

    public function test_a_retry_with_the_same_key_is_recorded_once_and_the_key_cannot_be_reused_for_another_workplace(): void
    {
        $this->finalized();
        $w = $this->workplaces();
        $key = 'retry-key-0001';

        $first = $this->pay($w['Acme Co'], ['mode' => 'partial', 'amount' => '100', 'idempotency_key' => $key])->assertCreated();
        $second = $this->pay($w['Acme Co'], ['mode' => 'partial', 'amount' => '100', 'idempotency_key' => $key])->assertOk();

        $this->assertTrue($second->json('data.replayed'));
        $this->assertSame($first->json('data.payment_id'), $second->json('data.payment_id'));
        $this->assertSame(1, PayrollWorkplacePayment::query()->count());
        $this->assertSame('400.00', $this->workplaces()['Acme Co']['outstanding']);

        $this->pay($w['Beta Co'], ['idempotency_key' => $key])->assertStatus(409)->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertSame(1, PayrollWorkplacePayment::query()->count());
    }

    public function test_viewing_and_recording_follow_the_payroll_permissions(): void
    {
        $this->finalized();
        $acme = $this->workplaces()['Acme Co'];
        $viewer = $this->tokenForRoleWith(AdminRole::HrEmployee, ['hr.view', 'hr.payroll.view']);
        $none = $this->tokenForRoleWith(AdminRole::HrEmployee, ['hr.view']);
        $approver = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.payroll.view', 'hr.payroll.approve']);

        $this->assertNotEmpty($this->workplaces($viewer));
        $this->as($none)->getJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/workplace-payments")->assertForbidden();
        $this->pay($acme, [], $viewer)->assertForbidden();
        $this->pay($acme, [], $none)->assertForbidden();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/workplace-payments")->assertUnauthorized();
        $this->assertSame(0, PayrollWorkplacePayment::query()->count());

        $this->pay($acme, ['mode' => 'partial', 'amount' => '10'], $approver)->assertCreated();
        $this->assertSame(1, PayrollWorkplacePayment::query()->count());
    }

    public function test_a_mistaken_payment_is_voided_with_a_reason_by_a_super_admin_or_hr_manager_and_stays_in_the_history(): void
    {
        $this->finalized();
        $beta = $this->workplaces()['Beta Co'];
        $id = (int) $this->pay($beta, ['mode' => 'full'])->assertCreated()->json('data.payment_id');
        $this->assertSame('paid', $this->workplaces()['Beta Co']['status']);

        $officer = $this->tokenForRoleWith(AdminRole::HrEmployee, ['hr.view', 'hr.payroll.view', 'hr.payroll.approve']);
        $this->as($officer)->postJson("/api/v1/admin/hr/workplace-payments/{$id}/void", ['reason' => 'Entered by mistake'])->assertForbidden()->assertJsonPath('error_code', 'PAYROLL_PAYMENT_VOID_NOT_ALLOWED');
        $this->as($this->super)->postJson("/api/v1/admin/hr/workplace-payments/{$id}/void", [])->assertStatus(422);
        $this->as($this->super)->postJson("/api/v1/admin/hr/workplace-payments/{$id}/void", ['reason' => 'no'])->assertStatus(422);
        $this->assertSame('paid', $this->workplaces()['Beta Co']['status']);

        $manager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.payroll.view', 'hr.payroll.approve']);
        $this->as($manager)->postJson("/api/v1/admin/hr/workplace-payments/{$id}/void", ['reason' => 'Entered by mistake'])->assertOk()->assertJsonPath('data.workplace.status', 'unpaid');

        $beta = $this->workplaces()['Beta Co'];
        $this->assertSame(['unpaid', '400.00', '0.00'], [$beta['status'], $beta['outstanding'], $beta['paid']]);
        $this->assertCount(1, $beta['payments'], 'the voided payment stays in the history');
        $this->assertTrue($beta['payments'][0]['voided']);
        $this->assertSame('Entered by mistake', $beta['payments'][0]['void_reason']);
        $this->assertSame(1, PayrollWorkplacePayment::query()->count(), 'nothing is deleted');

        $this->as($this->super)->postJson("/api/v1/admin/hr/workplace-payments/{$id}/void", ['reason' => 'Voiding twice'])->assertStatus(422)->assertJsonPath('error_code', 'PAYMENT_ALREADY_VOIDED');

        // The balance is open again and can be paid correctly.
        $this->pay($beta, ['mode' => 'partial', 'amount' => '400'])->assertCreated()->assertJsonPath('data.workplace.status', 'paid');
    }

    public function test_lines_marked_paid_one_by_one_count_toward_the_workplace_and_the_two_ways_cannot_pay_more_than_the_total(): void
    {
        $this->finalized();
        $acme = $this->workplaces()['Acme Co'];

        // A 300.00 line paid the existing way: the workplace is partial, 200.00 outstanding.
        $line = PayrollItem::query()->where('payroll_run_id', $this->runId)->where('net_cents', 30000)->firstOrFail();
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$line->id}/pay", [])->assertOk();
        $w = $this->workplaces()['Acme Co'];
        $this->assertSame(['partial', '200.00', '300.00', 30000, 0], [$w['status'], $w['outstanding'], $w['paid'], $w['line_paid_cents'], $w['workplace_paid_cents']]);

        $this->pay($acme, ['mode' => 'partial', 'amount' => '200.01'])->assertStatus(422)->assertJsonPath('error_code', 'AMOUNT_EXCEEDS_OUTSTANDING');
        $this->pay($acme, ['mode' => 'full'])->assertCreated()->assertJsonPath('data.workplace.status', 'paid');
        $this->assertSame(20000, (int) PayrollWorkplacePayment::query()->firstOrFail()->amount_cents, 'the full payment is the outstanding balance, not the total');

        // The remaining 200.00 line can no longer be marked paid on top of the workplace payment.
        $other = PayrollItem::query()->where('payroll_run_id', $this->runId)->where('net_cents', 20000)->where('payment_status', 'unpaid')->firstOrFail();
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$other->id}/pay", [])->assertStatus(422)->assertJsonPath('error_code', 'WORKPLACE_PAYMENT_COVERS_LINE');
        $this->assertSame('unpaid', $other->refresh()->payment_status);
    }

    public function test_marking_a_line_paid_works_exactly_as_before_when_no_workplace_payment_exists(): void
    {
        $this->finalized();
        $before = EmployeePayment::query()->count();

        foreach (PayrollItem::query()->where('payroll_run_id', $this->runId)->get() as $line) {
            $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$line->id}/pay", [])->assertOk();
        }

        $this->assertSame($before + 6, EmployeePayment::query()->count());
        $this->assertSame(['paid'], array_values(array_unique(array_column($this->workplaces(), 'status'))));
        $this->assertSame(0, PayrollWorkplacePayment::query()->count());
    }

    public function test_a_void_of_a_line_payment_reopens_the_workplace_balance(): void
    {
        $this->finalized();
        $line = PayrollItem::query()->where('payroll_run_id', $this->runId)->where('net_cents', 40000)->firstOrFail();
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$line->id}/pay", [])->assertOk();
        $this->assertSame('paid', $this->workplaces()['Beta Co']['status']);

        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$line->id}/void-payment", ['reason' => 'Paid to the wrong account'])->assertOk();
        $this->assertSame(['unpaid', '400.00'], [$this->workplaces()['Beta Co']['status'], $this->workplaces()['Beta Co']['outstanding']]);
    }

    public function test_workplace_payments_are_audited_without_changing_payroll(): void
    {
        $this->finalized();
        $before = $this->payrollFingerprint();
        $gamma = $this->workplaces()['Gamma Guri'];

        $id = (int) $this->pay($gamma, ['mode' => 'partial', 'amount' => '75.25', 'note' => 'First instalment'])->assertCreated()->json('data.payment_id');
        $this->as($this->super)->postJson("/api/v1/admin/hr/workplace-payments/{$id}/void", ['reason' => 'Wrong amount typed'])->assertOk();

        $events = AuditLog::query()->where('entity_type', PayrollWorkplacePayment::class)->where('entity_id', $id)->get()->map(fn ($log) => $log->metadata['event'] ?? null)->all();
        $this->assertContains('workplace_payment_recorded', $events);
        $this->assertContains('workplace_payment_voided', $events);
        $this->assertSame('First instalment', PayrollWorkplacePayment::query()->findOrFail($id)->note);
        $this->assertSame($before, $this->payrollFingerprint());
    }

    public function test_the_table_refuses_an_empty_workplace_or_a_non_positive_amount_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The CHECK constraints exist on PostgreSQL only.');
        }

        $id = $this->finalized();
        $row = ['payroll_run_id' => $id, 'period' => '2026-09', 'payment_date' => '2026-12-01', 'idempotency_key' => 'k', 'created_at' => now(), 'updated_at' => now()];

        foreach ([['amount_cents' => 0, 'client_company_id' => $this->acme->id], ['amount_cents' => 100, 'client_company_id' => null]] as $bad) {
            // A savepoint per attempt: PostgreSQL aborts the surrounding test transaction after a refused insert.
            DB::beginTransaction();

            try {
                DB::table('payroll_workplace_payments')->insert($row + $bad);
                $this->fail('The database accepted an invalid workplace payment.');
            } catch (QueryException) {
                $this->assertTrue(true);
            } finally {
                DB::rollBack();
            }
        }
    }
}
