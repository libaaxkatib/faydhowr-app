<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Actions\Hr\TransitionPayrollRunAction;
use App\Actions\Hr\TransitionPayrollRunSectionAction;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Models\PayrollWorkplacePayment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FinalizesCompanies;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decision 2026-11-04: a company is APPROVED by being paid in full, and the month is approved by itself when every company is. "Approve Paid" pays a
 * finalized company's whole outstanding balance, "Approve Partial" any amount up to it (the company stays Partially paid, NOT approved). Reviewing or
 * finalizing never approves; payroll amounts and finalization history are never touched; every payment and every automatic approval is audited.
 * Synthetic data only. September 2026, everybody assigned the whole month, so a line is exactly the monthly salary:
 *   Alpha Co 600 (a1 300 + a2 200 + x 100), Bravo Co 550 (b1 400 + x 150), Gury (guri) 250.
 */
class CompanyPaymentApprovalTest extends TestCase
{
    use FinalizesCompanies;
    use HrFixtures;
    use RefreshDatabase;

    private const P = '2026-09';

    private ClientCompany $alpha;

    private ClientCompany $bravo;

    private ClientCompany $gury;

    private Admin $superAdmin;

    private string $super;

    private int $runId;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-05 10:00:00');
        [$this->superAdmin, $this->super] = $this->superAdminToken();

        $this->alpha = $this->makeCompany('Alpha Co');
        $this->bravo = $this->makeCompany('Bravo Co');
        $this->gury = $this->makeCompany('Gury One', 'guri');
        $x = $this->makeEmployee();
        $this->makeAssignment($x, $this->alpha, null, 100);
        $this->makeAssignment($x, $this->bravo, null, 150);
        $this->makeAssignment($this->makeEmployee(), $this->alpha, null, 300);
        $this->makeAssignment($this->makeEmployee(), $this->alpha, null, 200);
        $this->makeAssignment($this->makeEmployee(), $this->bravo, null, 400);
        $this->makeAssignment($this->makeEmployee(), $this->gury, null, 250);

        $this->runId = (int) $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => self::P])->assertCreated()->json('data.id');
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

    private function section(ClientCompany $company): PayrollRunSection
    {
        return PayrollRunSection::query()->where('payroll_run_id', $this->runId)->where('client_company_id', $company->id)->firstOrFail();
    }

    private function finalize(ClientCompany ...$companies): void
    {
        foreach ($companies as $company) {
            $this->as($this->super)->patchJson('/api/v1/admin/hr/payroll-run-sections/'.$this->section($company)->id.'/review')->assertOk();
            $this->as($this->super)->patchJson('/api/v1/admin/hr/payroll-run-sections/'.$this->section($company)->id.'/finalize')->assertOk();
        }
    }

    private function pay(ClientCompany $company, string $mode, ?string $amount = null, ?string $key = null, ?string $token = null)
    {
        return $this->as($token ?? $this->super)->postJson('/api/v1/admin/hr/payroll-run-sections/'.$this->section($company)->id.'/payments', array_filter([
            'mode' => $mode, 'amount' => $amount, 'payment_date' => '2026-10-05', 'note' => 'Cash handed over', 'idempotency_key' => $key ?? 'key-'.bin2hex(random_bytes(6)),
        ], fn ($v) => $v !== null));
    }

    /** @return array<string, mixed> */
    private function month(): array
    {
        return $this->as($this->super)->getJson("/api/v1/admin/hr/payroll-runs/{$this->runId}")->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function row(ClientCompany $company): array
    {
        return collect($this->month()['sections'])->firstWhere('client_company_id', $company->id);
    }

    /** @return list<string> */
    private function events(?string $entity = null): array
    {
        return AuditLog::query()->when($entity !== null, fn ($q) => $q->where('entity_type', $entity))->get()->map(fn ($log) => $log->metadata['event'] ?? '')->filter()->values()->all();
    }

    private function fingerprint(): array
    {
        return PayrollItem::query()->orderBy('id')->get(['id', 'gross_cents', 'net_cents', 'replacement_deduction_cents', 'other_deduction_cents'])->toArray();
    }

    // ------------------------------------------------------------------------------------------------ Approve Paid

    public function test_approve_paid_pays_the_whole_outstanding_balance_and_approves_the_company_without_a_second_step(): void
    {
        $this->finalize($this->alpha);
        $before = $this->fingerprint();
        $this->assertSame(['finalized', 'unpaid', false], [$this->row($this->alpha)['status'], $this->row($this->alpha)['payment_status'], $this->row($this->alpha)['approved']]);

        $this->pay($this->alpha, 'full')->assertCreated()->assertJsonPath('data.company.approved', true)->assertJsonPath('data.company.payment_status', 'approved');

        $row = $this->row($this->alpha);
        $this->assertSame(['finalized', 'approved', true, '600.00', '0.00'], [$row['status'], $row['payment_status'], $row['approved'], $row['paid'], $row['outstanding']]);
        $payment = PayrollWorkplacePayment::query()->sole();
        $this->assertSame([60000, self::P, $this->alpha->id, $this->superAdmin->id, '2026-10-05', 'Cash handed over'], [(int) $payment->amount_cents, $payment->period, $payment->client_company_id, $payment->recorded_by, $payment->payment_date->toDateString(), $payment->note]);
        $this->assertSame($before, $this->fingerprint(), 'no payroll line, salary or finalized amount is touched');
        $this->assertSame('finalized', $this->section($this->alpha)->status, 'approval never changes the payroll status');
        $this->assertContains('payroll_company_approved', $this->events(PayrollRunSection::class));
        $this->assertContains('workplace_payment_recorded', $this->events());
    }

    public function test_finalizing_or_reviewing_never_approves_a_company(): void
    {
        $this->finalize($this->alpha);

        $month = $this->month();
        $this->assertSame([false, 'unpaid'], [$this->row($this->alpha)['approved'], $this->row($this->alpha)['payment_status']]);
        $this->assertFalse($month['approval']['fully_approved']);
        $this->assertSame(0, $month['approval']['approved_companies']);
        $this->assertNotContains('payroll_company_approved', $this->events());
    }

    public function test_only_a_finalized_company_can_be_paid_and_nothing_is_recorded_for_the_others(): void
    {
        $this->as($this->super)->patchJson('/api/v1/admin/hr/payroll-run-sections/'.$this->section($this->bravo)->id.'/review')->assertOk();

        $this->pay($this->bravo, 'full')->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_NOT_FINALIZED');
        $this->pay($this->gury, 'partial', '10.00')->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_NOT_FINALIZED');
        $this->assertSame(0, PayrollWorkplacePayment::query()->count());
    }

    // ------------------------------------------------------------------------------------------------ Approve Partial

    public function test_approve_partial_keeps_the_company_unapproved_and_payments_accumulate_until_the_balance_is_zero(): void
    {
        $this->finalize($this->alpha);

        $this->pay($this->alpha, 'partial', '200.00')->assertCreated()->assertJsonPath('data.company.approved', false)->assertJsonPath('data.company.payment_status', 'partially_paid');
        $row = $this->row($this->alpha);
        $this->assertSame(['partially_paid', false, '200.00', '400.00', false], [$row['payment_status'], $row['approved'], $row['paid'], $row['outstanding'], $this->month()['approval']['fully_approved']]);
        $this->assertNotContains('payroll_company_approved', $this->events());

        $this->pay($this->alpha, 'partial', '150.50')->assertCreated();
        $this->assertSame(['partially_paid', '350.50', '249.50'], [$this->row($this->alpha)['payment_status'], $this->row($this->alpha)['paid'], $this->row($this->alpha)['outstanding']]);

        // The cumulative payments reach the total: the company is approved by itself (no second action).
        $this->pay($this->alpha, 'partial', '249.50')->assertCreated()->assertJsonPath('data.company.approved', true);
        $row = $this->row($this->alpha);
        $this->assertSame(['approved', true, '600.00', '0.00'], [$row['payment_status'], $row['approved'], $row['paid'], $row['outstanding']]);
        $this->assertSame(3, PayrollWorkplacePayment::query()->count(), 'every partial payment stays as its own record');
        $this->assertSame(1, count(array_filter($this->events(PayrollRunSection::class), fn ($e) => $e === 'payroll_company_approved')), 'approved once, when the balance reached zero');
    }

    public function test_a_partial_payment_must_be_more_than_zero_and_never_more_than_what_is_outstanding(): void
    {
        $this->finalize($this->alpha);
        $this->pay($this->alpha, 'partial', '100.00')->assertCreated();

        $this->pay($this->alpha, 'partial', '0.00')->assertStatus(422)->assertJsonPath('error_code', 'AMOUNT_INVALID');
        $this->pay($this->alpha, 'partial', '500.01')->assertStatus(422)->assertJsonPath('error_code', 'AMOUNT_EXCEEDS_OUTSTANDING');
        $this->pay($this->alpha, 'partial', '-5')->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR');
        $this->pay($this->alpha, 'partial', '10.123')->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR');
        $this->pay($this->alpha, 'partial')->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR');
        $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-run-sections/'.$this->section($this->alpha)->id.'/payments', ['mode' => 'full', 'payment_date' => '2026-10-06', 'idempotency_key' => 'future-date-1'])
            ->assertStatus(422)->assertJsonPath('error_code', 'PAYMENT_DATE_IN_FUTURE');

        $this->assertSame(1, PayrollWorkplacePayment::query()->count(), 'nothing was recorded by the refused requests');
        $this->assertSame('500.00', $this->row($this->alpha)['outstanding']);
    }

    // ------------------------------------------------------------------------------------------------ duplicates and overpayment

    public function test_a_retry_or_double_click_with_the_same_key_is_recorded_once(): void
    {
        $this->finalize($this->alpha);

        $this->pay($this->alpha, 'full', null, 'one-key-0001')->assertCreated()->assertJsonPath('data.replayed', false);
        $this->pay($this->alpha, 'full', null, 'one-key-0001')->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, PayrollWorkplacePayment::query()->count());
        $this->assertSame('600.00', $this->row($this->alpha)['paid']);

        // The same key for another company is refused, and a new key on an already paid company pays nothing more.
        $this->finalize($this->bravo);
        $this->pay($this->bravo, 'full', null, 'one-key-0001')->assertStatus(409)->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_REUSED');
        $this->pay($this->alpha, 'full', null, 'another-key-1')->assertStatus(422)->assertJsonPath('error_code', 'WORKPLACE_NOTHING_OUTSTANDING');
        $this->assertSame(1, PayrollWorkplacePayment::query()->count());
    }

    public function test_money_is_exact_to_the_cent_and_the_company_totals_reconcile_with_the_month(): void
    {
        $this->finalize($this->alpha, $this->bravo, $this->gury);
        $this->pay($this->alpha, 'partial', '0.01')->assertCreated();
        $this->pay($this->bravo, 'partial', '549.99')->assertCreated();
        $this->pay($this->gury, 'full')->assertCreated();

        $month = $this->month();
        $sum = fn (string $f): int => (int) array_sum(array_column($month['sections'], $f));
        $this->assertSame([$month['approval']['total_cents'], $month['approval']['paid_cents'], $month['approval']['outstanding_cents']], [$sum('payable_cents'), $sum('paid_cents'), $sum('outstanding_cents')]);
        $this->assertSame([140000, 25000 + 1 + 54999, 140000 - 80000], [$month['approval']['total_cents'], $month['approval']['paid_cents'], $month['approval']['outstanding_cents']]);
        $this->assertSame((int) PayrollWorkplacePayment::query()->sum('amount_cents'), $month['approval']['paid_cents']);
    }

    // ------------------------------------------------------------------------------------------------ the month

    public function test_the_month_is_approved_by_itself_only_when_every_company_is_fully_paid(): void
    {
        $this->finalize($this->alpha, $this->bravo, $this->gury);
        $this->assertSame('finalized', $this->month()['status']);

        $this->pay($this->alpha, 'full')->assertCreated();
        $a = $this->month()['approval'];
        $this->assertSame([3, 1, 2, 0, 0, false], [$a['total_companies'], $a['approved_companies'], $a['unpaid_companies'], $a['partially_paid_companies'], $a['not_finalized_companies'], $a['fully_approved']]);

        $this->pay($this->bravo, 'partial', '300.00')->assertCreated(); // a partial payment is not approval
        $a = $this->month()['approval'];
        $this->assertSame([3, 1, 1, 1, false, 50000], [$a['total_companies'], $a['approved_companies'], $a['unpaid_companies'], $a['partially_paid_companies'], $a['fully_approved'], $a['outstanding_cents']]);
        $this->assertNotContains('payroll_month_approved', $this->events());

        $this->pay($this->gury, 'full')->assertCreated();
        $this->assertFalse($this->month()['approval']['fully_approved'], 'one company still has a balance');

        $this->pay($this->bravo, 'full')->assertCreated(); // the last balance
        $a = $this->month()['approval'];
        $this->assertSame([3, 3, 0, 0, true, 0], [$a['total_companies'], $a['approved_companies'], $a['unpaid_companies'], $a['partially_paid_companies'], $a['fully_approved'], $a['outstanding_cents']]);
        $this->assertSame(1, count(array_filter($this->events(PayrollRun::class), fn ($e) => $e === 'payroll_month_approved')));
        $this->assertSame('finalized', $this->month()['status'], 'approval is derived: the payroll status is never changed');
    }

    public function test_a_month_with_a_company_that_is_not_finalized_cannot_be_approved(): void
    {
        $this->finalize($this->alpha, $this->bravo);
        $this->pay($this->alpha, 'full')->assertCreated();
        $this->pay($this->bravo, 'full')->assertCreated();

        $a = $this->month()['approval'];
        $this->assertFalse($a['fully_approved']);
        $this->assertSame([3, 2, 1], [$a['total_companies'], $a['approved_companies'], $a['not_finalized_companies']]);
    }

    public function test_voiding_a_payment_withdraws_the_approval_of_the_company_and_the_month_and_says_so(): void
    {
        $this->finalize($this->alpha, $this->bravo, $this->gury);
        $this->pay($this->alpha, 'full')->assertCreated();
        $this->pay($this->bravo, 'full')->assertCreated();
        $this->pay($this->gury, 'full')->assertCreated();
        $this->assertTrue($this->month()['approval']['fully_approved']);

        $payment = PayrollWorkplacePayment::query()->where('client_company_id', $this->gury->id)->firstOrFail();
        $this->as($this->super)->postJson("/api/v1/admin/hr/workplace-payments/{$payment->id}/void", ['reason' => 'Recorded against the wrong company'])->assertOk();

        $a = $this->month()['approval'];
        $this->assertSame([false, 2, 1, 25000], [$a['fully_approved'], $a['approved_companies'], $a['unpaid_companies'], $a['outstanding_cents']]);
        $this->assertSame([false, 'unpaid'], [$this->row($this->gury)['approved'], $this->row($this->gury)['payment_status']]);
        $this->assertContains('payroll_company_approval_withdrawn', $this->events());
        $this->assertContains('payroll_month_approval_withdrawn', $this->events());
        $this->assertSame(1, PayrollWorkplacePayment::query()->whereNotNull('voided_at')->count(), 'the voided payment stays in the history');
    }

    public function test_a_line_paid_one_by_one_that_clears_the_last_cent_also_approves_the_company(): void
    {
        $this->finalize($this->gury);
        $item = PayrollItem::query()->where('payroll_run_id', $this->runId)->where('section_id', $this->section($this->gury)->id)->firstOrFail();

        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$item->id}/pay", [])->assertOk();

        $this->assertSame([true, 'approved'], [$this->row($this->gury)['approved'], $this->row($this->gury)['payment_status']]);
        $this->assertContains('payroll_company_approved', $this->events(PayrollRunSection::class));
    }

    // ------------------------------------------------------------------------------------------------ several workplaces in one company

    public function test_a_company_with_several_workplaces_is_paid_workplace_by_workplace_never_more_than_each_is_owed(): void
    {
        $delta = $this->makeCompany('Delta Co');
        $north = $this->makeClientLocation($delta, 'North');
        $south = $this->makeClientLocation($delta, 'South');
        $this->makeAssignment($this->makeEmployee(), $delta, $north, 300);
        $this->makeAssignment($this->makeEmployee(), $delta, $south, 200);
        $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => self::P])->assertCreated();
        $this->finalize($delta);

        $this->pay($delta, 'partial', '350.00')->assertCreated()->assertJsonPath('data.company.payment_status', 'partially_paid');
        $workplaces = collect($this->as($this->super)->getJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/workplace-payments")->json('data.workplaces'))->where('client_company_id', $delta->id);
        $this->assertSame(2, $workplaces->count());
        $this->assertSame(350.0, (float) $workplaces->sum('paid'));
        $this->assertTrue($workplaces->every(fn (array $w): bool => $w['paid_cents'] <= $w['total_cents']), 'no workplace is paid more than it is owed');

        $this->pay($delta, 'full')->assertCreated()->assertJsonPath('data.company.approved', true);
        $this->assertSame(500.0, (float) collect($this->as($this->super)->getJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/workplace-payments")->json('data.workplaces'))->where('client_company_id', $delta->id)->sum('paid'));
    }

    // ------------------------------------------------------------------------------------------------ permissions and the removed buttons

    public function test_recording_needs_the_payroll_approve_permission_and_a_reviewer_cannot_pay(): void
    {
        $this->finalize($this->alpha);
        $preparer = $this->tokenWithOnly(['hr.view', 'hr.payroll.view', 'hr.payroll.manage']);
        $viewer = $this->tokenWithOnly(['hr.payroll.view']);
        $manager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.payroll.view', 'hr.payroll.manage', 'hr.payroll.approve']);

        $this->pay($this->alpha, 'full', null, null, $preparer)->assertForbidden();
        $this->pay($this->alpha, 'full', null, null, $viewer)->assertForbidden();
        $this->assertSame(0, PayrollWorkplacePayment::query()->count());

        $this->pay($this->alpha, 'full', null, null, $manager)->assertCreated();
        $this->assertSame((int) Admin::query()->where('role', AdminRole::HrManager)->value('id'), (int) PayrollWorkplacePayment::query()->sole()->recorded_by, 'the record names the person who really paid');
    }

    public function test_there_is_no_manual_approve_for_the_month_or_a_company(): void
    {
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/approve")->assertNotFound();
        $this->as($this->super)->patchJson('/api/v1/admin/hr/payroll-run-sections/'.$this->section($this->alpha)->id.'/approve')->assertNotFound();
        $this->assertFalse(method_exists(TransitionPayrollRunAction::class, 'approve'));
        $this->assertFalse(method_exists(TransitionPayrollRunSectionAction::class, 'approve'));
    }

    public function test_the_company_payment_route_names_exactly_one_section_and_uses_the_existing_permission(): void
    {
        $routes = collect($this->app['router']->getRoutes()->getRoutes())->filter(fn ($r): bool => str_contains($r->uri(), 'payroll-run-sections') && in_array('POST', $r->methods(), true));
        $this->assertSame(['api/v1/admin/hr/payroll-run-sections/{payrollRunSection}/payments'], $routes->map(fn ($r): string => $r->uri())->values()->all());
        $this->assertContains('permission:hr.payroll.approve', $routes->first()->gatherMiddleware());
    }

    public function test_the_payment_pages_and_the_excel_follow_the_same_company_state(): void
    {
        $this->finalize($this->alpha);
        $this->pay($this->alpha, 'partial', '100.00')->assertCreated();

        $workplace = collect($this->as($this->super)->getJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/workplace-payments")->json('data.workplaces'))->firstWhere('client_company_id', $this->alpha->id);
        $row = $this->row($this->alpha);
        $this->assertSame([$workplace['paid'], $workplace['outstanding'], $workplace['status']], [$row['paid'], $row['outstanding'], 'partial']);
        $this->assertSame('partially_paid', $row['payment_status']);
    }
}
