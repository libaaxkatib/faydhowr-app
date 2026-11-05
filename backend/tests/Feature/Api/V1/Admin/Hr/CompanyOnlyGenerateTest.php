<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Models\PayrollSectionReopening;
use App\Models\PayrollWorkplacePayment;
use App\Support\Hr\Payroll\CorrectionAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FinalizesCompanies;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * HR Release 1A (owner decisions N-2, N-2a, N-2b, N-4, N-4a, N-5, N-6, D-1, D-2, D-3, D-5, D-8): payroll is generated ONE COMPANY at a time (there is no
 * month-wide Generate and no month-level bulk Review), the Temporary Replacement checks are warnings only, every assigned company is listed whether it was
 * generated or not, a payment may carry an optional reference, and a company with a recorded payment can be reopened only by a Super Admin.
 * Synthetic data only.
 */
class CompanyOnlyGenerateTest extends TestCase
{
    use FinalizesCompanies;
    use HrFixtures;
    use RefreshDatabase;

    private const P = '2026-10';

    private ClientCompany $alpha;

    private ClientCompany $bravo;

    private ClientCompany $gury;

    private string $super;

    private string $hrManager;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-05 10:00:00');
        [, $this->super] = $this->superAdminToken();
        $this->hrManager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage', 'hr.payroll.view', 'hr.payroll.manage', 'hr.payroll.approve']);

        $this->alpha = $this->makeCompany('Alpha Co');
        $this->bravo = $this->makeCompany('Bravo Co');
        $this->gury = $this->makeCompany('Gury One', 'guri');
        $this->makeAssignment($this->makeEmployee(), $this->alpha, null, 300, '2026-01-01');
        $this->makeAssignment($this->makeEmployee(), $this->alpha, null, 200, '2026-01-01');
        $this->makeAssignment($this->makeEmployee(), $this->bravo, null, 400, '2026-01-01');
        $this->makeAssignment($this->makeEmployee(), $this->gury, null, 250, '2026-01-01');
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

    private function generate(ClientCompany|string $company, ?string $token = null, ?string $expected = null)
    {
        $key = $company instanceof ClientCompany ? $company->id : $company;

        return $this->as($token ?? $this->super)->json('POST', '/api/v1/admin/hr/payroll/'.self::P."/companies/{$key}/generate", $expected === null ? [] : ['expected_status' => $expected]);
    }

    private function section(ClientCompany $company): ?PayrollRunSection
    {
        return PayrollRunSection::query()->whereHas('run', fn ($q) => $q->where('period', self::P))->where('client_company_id', $company->id)->first();
    }

    private function snapshot(ClientCompany $company): array
    {
        $s = $this->section($company);

        return $s === null ? [] : [$s->status, $s->generated_at?->toIso8601String(), $s->totals, PayrollItem::query()->where('section_id', $s->id)->orderBy('id')->pluck('net_cents', 'id')->all()];
    }

    // ------------------------------------------------------------------------------------------------ N-2a / D-2: no month-wide actions

    public function test_there_is_no_month_wide_generate_and_no_month_level_bulk_review(): void
    {
        $this->as($this->super)->json('POST', '/api/v1/admin/hr/payroll-runs', ['period' => self::P])->assertStatus(405);
        $this->assertSame(0, PayrollRun::query()->count());

        $this->generate($this->alpha)->assertCreated();
        $run = PayrollRun::query()->firstOrFail();
        $this->as($this->super)->json('PATCH', "/api/v1/admin/hr/payroll-runs/{$run->id}/review")->assertNotFound();
        $this->assertSame('draft', $this->section($this->alpha)->status, 'nothing was reviewed');
    }

    // ------------------------------------------------------------------------------------------------ N-2: only the selected company is written

    public function test_generating_one_company_writes_only_that_company_and_never_touches_another(): void
    {
        $this->generate($this->alpha)->assertCreated()->assertJsonPath('data.status', 'draft');

        $this->assertNotNull($this->section($this->alpha));
        $this->assertNull($this->section($this->bravo), 'a company that was not generated has no section');
        $this->assertNull($this->section($this->gury));

        $this->generate($this->bravo)->assertCreated();
        $alpha = $this->snapshot($this->alpha);

        // Review Alpha, then generate Gury and Bravo again: Alpha keeps exactly what it was (still reviewed, same lines, same stamp).
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$this->section($this->alpha)->id}/review")->assertOk();
        $alphaReviewed = $this->snapshot($this->alpha);
        $this->generate($this->gury)->assertCreated();
        $this->generate($this->bravo, null, 'draft')->assertCreated();

        $this->assertSame($alphaReviewed, $this->snapshot($this->alpha));
        $this->assertSame('reviewed', $this->section($this->alpha)->status);
        $this->assertNotSame($alpha[0], $alphaReviewed[0]);
        $this->assertNotNull($this->section($this->bravo)->generated_at);
        $this->assertSame(1, $this->section($this->bravo)->generatedBy()->count());
    }

    public function test_the_calculation_is_the_same_as_before_only_the_writing_is_per_company(): void
    {
        $this->generate($this->alpha)->assertCreated();
        $this->generate($this->bravo)->assertCreated();
        $this->generate($this->gury)->assertCreated();

        $this->assertSame([50000, 40000, 25000], [
            (int) PayrollItem::query()->where('section_id', $this->section($this->alpha)->id)->sum('net_cents'),
            (int) PayrollItem::query()->where('section_id', $this->section($this->bravo)->id)->sum('net_cents'),
            (int) PayrollItem::query()->where('section_id', $this->section($this->gury)->id)->sum('net_cents'),
        ]);
        $this->assertSame(115000, (int) PayrollRun::query()->firstOrFail()->totals['net_cents']);
    }

    public function test_regenerating_a_reviewed_company_resets_it_to_draft_and_a_stale_screen_is_refused(): void
    {
        $this->generate($this->alpha)->assertCreated();
        $id = $this->section($this->alpha)->id;
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$id}/review")->assertOk();

        // The screen still showed "draft": the section moved to reviewed meanwhile, so nothing is regenerated.
        $this->generate($this->alpha, null, 'draft')->assertStatus(409)->assertJsonPath('error_code', 'PAYROLL_SECTION_CHANGED');
        $this->assertSame('reviewed', $this->section($this->alpha)->status);

        $this->generate($this->alpha, null, 'reviewed')->assertCreated();
        $this->assertSame('draft', $this->section($this->alpha)->status);
    }

    public function test_an_approved_finalized_or_legacy_approved_company_is_refused_and_stays_exactly_as_it_was(): void
    {
        foreach ([$this->alpha, $this->bravo, $this->gury] as $company) {
            $this->generate($company)->assertCreated();
        }

        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$this->section($this->alpha)->id}/review")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$this->section($this->alpha)->id}/finalize")->assertOk();
        $this->section($this->bravo)->update(['status' => PayrollRunSection::STATUS_APPROVED]); // the legacy status of the 112 November sections

        $before = [$this->snapshot($this->alpha), $this->snapshot($this->bravo)];

        $this->generate($this->alpha)->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_COMPANY_LOCKED');
        $this->generate($this->bravo)->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_COMPANY_LOCKED');

        $this->assertSame($before, [$this->snapshot($this->alpha), $this->snapshot($this->bravo)]);
    }

    public function test_a_company_that_is_not_assigned_to_the_month_is_refused(): void
    {
        $other = $this->makeCompany('Nobody Here');

        $this->generate($other)->assertStatus(422)->assertJsonPath('error_code', 'COMPANY_NOT_ASSIGNED_IN_PERIOD');
        $this->assertNull($this->section($other));
    }

    // ------------------------------------------------------------------------------------------------ D-3: nothing payable

    public function test_a_company_with_nothing_payable_keeps_a_visible_draft_section(): void
    {
        $empty = $this->makeCompany('Temp Only Co');
        $this->makeAssignment($this->makeEmployee('active', 'temporary'), $empty, null, 100, '2026-01-01');

        $response = $this->generate($empty)->assertCreated();
        $section = $this->section($empty);

        $this->assertNotNull($section);
        $this->assertSame('draft', $section->status);
        $this->assertSame(0, (int) $section->totals['item_count']);
        $row = collect($response->json('data.sections'))->firstWhere('client_company_id', $empty->id);
        $this->assertTrue($row['nothing_payable']);

        $overview = $this->as($this->super)->getJson('/api/v1/admin/hr/payroll/'.self::P.'/overview')->assertOk()->json('data');
        $this->assertTrue(collect($overview['companies'])->firstWhere('client_company_id', $empty->id)['nothing_payable']);
    }

    // ------------------------------------------------------------------------------------------------ N-2b / D-1 / D-8: warnings only

    private function unresolvedTemporaryAsReplacement(ClientCompany $company): EmployeeWorkAssignment
    {
        $temp = $this->makeEmployee('active', 'temporary');
        $assignment = $this->makeAssignment($temp, $company, null, 100, '2026-01-01');
        $assignment->update(['assignment_type' => 'replacement']);

        return $assignment->refresh();
    }

    public function test_an_unresolved_temporary_replacement_is_a_warning_and_never_blocks_generate_review_or_finalize(): void
    {
        $assignment = $this->unresolvedTemporaryAsReplacement($this->alpha);

        // Before Generate: the warning is available, names the company and the person, explains what is missing, and is flagged non-blocking.
        $check = $this->as($this->super)->getJson('/api/v1/admin/hr/payroll/'.self::P."/companies/{$this->alpha->id}/warnings")->assertOk();
        $check->assertJsonPath('data.blocking', false);
        $warning = collect($check->json('data.warnings'))->firstWhere('condition', 'A');
        $this->assertSame($this->alpha->id, $warning['client_company_id']);
        $this->assertSame($assignment->id, $warning['assignment_id']);
        $this->assertFalse($warning['blocking']);
        $this->assertStringContainsString('No payroll line is calculated', $warning['what_remains']);

        // Generate proceeds (201) with no confirmation, stores the warning on the section and audits it.
        $this->generate($this->alpha)->assertCreated();
        $section = $this->section($this->alpha);
        $this->assertSame('A', collect($section->generation_warnings)->first(fn ($w) => $w['condition'] === 'A')['condition']);
        $this->assertNotNull(AuditLog::query()->where('entity_type', PayrollRunSection::class)->where('entity_id', $section->id)->get()->first(fn ($l) => ($l->metadata['event'] ?? '') === 'payroll_company_generated' && count($l->metadata['warnings']) > 0));

        // Review and Finalize are not blocked either, and the warning is still shown for them.
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/review")->assertOk();
        $this->as($this->super)->getJson('/api/v1/admin/hr/payroll/'.self::P."/companies/{$this->alpha->id}/warnings")->assertJsonPath('data.warnings.0.condition', 'A');
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/finalize")->assertOk();
    }

    public function test_the_warning_belongs_to_its_own_company_only(): void
    {
        $this->unresolvedTemporaryAsReplacement($this->alpha);

        $bravo = $this->as($this->super)->getJson('/api/v1/admin/hr/payroll/'.self::P."/companies/{$this->bravo->id}/warnings")->assertOk();
        $this->assertSame([], $bravo->json('data.warnings'));
        $this->generate($this->bravo)->assertCreated();
        $this->assertSame([], $this->section($this->bravo)->generation_warnings);
    }

    // ------------------------------------------------------------------------------------------------ N-4 / N-4a: every assigned company

    public function test_the_overview_lists_every_assigned_company_generated_or_not_and_never_invents_a_total(): void
    {
        $this->generate($this->alpha)->assertCreated();

        $data = $this->as($this->super)->getJson('/api/v1/admin/hr/payroll/'.self::P.'/overview')->assertOk()->json('data');
        $rows = collect($data['companies'])->keyBy('client_company_id');

        $this->assertCount(3, $rows);
        $this->assertSame('draft', $rows[$this->alpha->id]['state']);
        $this->assertSame(50000, $rows[$this->alpha->id]['payroll_total_cents']);
        $this->assertSame('not_generated', $rows[$this->bravo->id]['state']);
        $this->assertNull($rows[$this->bravo->id]['payroll_total_cents'], 'a company that was not generated has no total (shown "-"), not zero');
        $this->assertNull($rows[$this->bravo->id]['payments_received_cents']);
        $this->assertSame(['companies_assigned' => 3, 'companies_generated' => 1, 'not_generated' => 2, 'all_companies_paid' => false], collect($data['summary'])->only(['companies_assigned', 'companies_generated', 'not_generated', 'all_companies_paid'])->all());
        $this->assertSame(50000, $data['summary']['payroll_total_cents']);
    }

    public function test_all_companies_paid_only_when_every_assigned_company_is_generated_finalized_and_fully_paid(): void
    {
        foreach ([$this->alpha, $this->bravo] as $company) {
            $this->generate($company)->assertCreated();
        }

        $run = PayrollRun::query()->firstOrFail();
        $this->finalizeEveryCompany($run->id, $this->super);
        $this->approvePaid($this->section($this->alpha)->id, $this->super, 'pay-alpha-0001')->assertStatus(201);
        $this->approvePaid($this->section($this->bravo)->id, $this->super, 'pay-bravo-0001')->assertStatus(201);

        // Two companies are Paid but Gury was never generated: the month is NOT "All Companies Paid".
        $summary = $this->as($this->super)->getJson('/api/v1/admin/hr/payroll/'.self::P.'/overview')->json('data.summary');
        $this->assertSame([2, 1, false], [$summary['paid'], $summary['not_generated'], $summary['all_companies_paid']]);

        $this->generate($this->gury)->assertCreated();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$this->section($this->gury)->id}/review")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$this->section($this->gury)->id}/finalize")->assertOk();
        $summary = $this->as($this->super)->getJson('/api/v1/admin/hr/payroll/'.self::P.'/overview')->json('data.summary');
        $this->assertSame([2, 0, 1, false], [$summary['paid'], $summary['not_generated'], $summary['finalized_unpaid'], $summary['all_companies_paid']]);

        $this->approvePaid($this->section($this->gury)->id, $this->super, 'pay-gury-0001')->assertStatus(201);
        $summary = $this->as($this->super)->getJson('/api/v1/admin/hr/payroll/'.self::P.'/overview')->json('data.summary');
        $this->assertTrue($summary['all_companies_paid']);
        $this->assertSame(115000, $summary['payments_received_cents']);
        $this->assertSame(0, $summary['outstanding_cents']);
    }

    public function test_the_overview_and_generate_need_the_payroll_permissions(): void
    {
        $viewer = $this->tokenWithOnly(['hr.view', 'hr.payroll.view']);

        $this->generate($this->alpha, $viewer)->assertForbidden();
        $this->as($viewer)->getJson('/api/v1/admin/hr/payroll/'.self::P.'/overview')->assertOk();
        $this->as($this->noPermissionToken())->getJson('/api/v1/admin/hr/payroll/'.self::P.'/overview')->assertForbidden();
        $this->generate($this->alpha, $this->noPermissionToken())->assertForbidden();
        $this->assertNull($this->section($this->alpha));
    }

    // ------------------------------------------------------------------------------------------------ D-5: dashboard payroll KPIs

    public function test_the_dashboard_payroll_block_needs_payroll_view_and_defaults_to_the_previous_month(): void
    {
        $this->generate($this->alpha)->assertCreated();

        $without = $this->tokenWithOnly(['hr.dashboard.view']);
        $this->as($without)->getJson('/api/v1/admin/hr/dashboard')->assertOk()->assertJsonPath('data.payroll', null);

        $with = $this->tokenWithOnly(['hr.dashboard.view', 'hr.payroll.view']);
        $data = $this->as($with)->getJson('/api/v1/admin/hr/dashboard')->assertOk()->json('data.payroll');
        $this->assertSame('2026-10', $data['period']);
        $this->assertSame(3, $data['companies_assigned']);
        $this->assertSame(2, $data['not_generated']);
        $this->assertSame(50000, $data['payroll_total_cents']);

        $this->as($with)->getJson('/api/v1/admin/hr/dashboard?payroll_period=2026-09')->assertOk()->assertJsonPath('data.payroll.period', '2026-09');
        $this->as($with)->getJson('/api/v1/admin/hr/dashboard?payroll_period=bad')->assertStatus(422);
    }

    // ------------------------------------------------------------------------------------------------ N-5: optional payment reference

    private function finalizedAndPaidAlpha(bool $full = true): PayrollRunSection
    {
        $this->generate($this->alpha)->assertCreated();
        $section = $this->section($this->alpha);
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/review")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/finalize")->assertOk();

        if ($full) {
            $this->approvePaid($section->id, $this->super, 'pay-alpha-0001')->assertStatus(201);
        }

        return $section->refresh();
    }

    public function test_a_payment_reference_is_optional_stored_and_shown_and_old_payments_stay_valid(): void
    {
        $section = $this->finalizedAndPaidAlpha(false);
        $date = '2026-11-05';

        // Without a reference the payment is recorded exactly as before.
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/payments", ['mode' => 'partial', 'amount' => '100.00', 'payment_date' => $date, 'idempotency_key' => 'ref-none-0001'])->assertStatus(201);
        $this->assertNull(PayrollWorkplacePayment::query()->orderBy('id')->firstOrFail()->reference);

        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/payments", ['mode' => 'partial', 'amount' => '150.00', 'payment_date' => $date, 'reference' => '  MM-778812  ', 'idempotency_key' => 'ref-some-0001'])->assertStatus(201);
        $this->assertSame('MM-778812', PayrollWorkplacePayment::query()->orderByDesc('id')->firstOrFail()->reference);

        $payments = $this->as($this->super)->getJson('/api/v1/admin/hr/payroll-runs/'.PayrollRun::query()->value('id').'/workplace-payments')->assertOk()->json('data.workplaces.0.payments');
        $this->assertSame([null, 'MM-778812'], array_column($payments, 'reference'));

        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/payments", ['mode' => 'partial', 'amount' => '10.00', 'payment_date' => $date, 'reference' => str_repeat('x', 101), 'idempotency_key' => 'ref-long-0001'])->assertStatus(422);
    }

    // ------------------------------------------------------------------------------------------------ N-6: reopening a company that has a payment

    public function test_a_company_with_any_recorded_payment_can_be_reopened_only_by_a_super_admin(): void
    {
        $section = $this->finalizedAndPaidAlpha(false);
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/payments", ['mode' => 'partial', 'amount' => '100.00', 'payment_date' => '2026-11-05', 'idempotency_key' => 'part-0000001'])->assertStatus(201);

        // A PARTIAL payment counts: the HR Manager is refused, nothing changes, the payment is untouched.
        $this->as($this->hrManager)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/reopen", ['reason' => 'The salary of one employee was wrong'])
            ->assertForbidden()->assertJsonPath('error_code', 'PAID_COMPANY_REOPEN_SUPER_ADMIN_ONLY');
        $this->assertSame('finalized', $section->fresh()->status);
        $this->assertSame(10000, (int) PayrollWorkplacePayment::query()->sum('amount_cents'));

        // The Super Admin may; every payment is kept; who and why are recorded.
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/reopen", ['reason' => 'The salary of one employee was wrong'])->assertOk();
        $this->assertSame('draft', $section->fresh()->status);
        $this->assertSame(10000, (int) PayrollWorkplacePayment::query()->whereNull('voided_at')->sum('amount_cents'));
        $this->assertSame(1, PayrollSectionReopening::query()->where('payroll_run_section_id', $section->id)->count());
    }

    public function test_an_unpaid_finalized_company_can_still_be_reopened_by_the_hr_manager(): void
    {
        $section = $this->finalizedAndPaidAlpha(false);

        $this->as($this->hrManager)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/reopen", ['reason' => 'The salary of one employee was wrong'])->assertOk();
        $this->assertSame('draft', $section->fresh()->status);
    }

    public function test_a_voided_payment_no_longer_counts_as_a_recorded_payment(): void
    {
        $section = $this->finalizedAndPaidAlpha(true);
        $payment = PayrollWorkplacePayment::query()->firstOrFail();

        $this->as($this->hrManager)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/reopen", ['reason' => 'The salary of one employee was wrong'])->assertForbidden();
        $this->as($this->hrManager)->postJson("/api/v1/admin/hr/workplace-payments/{$payment->id}/void", ['reason' => 'Recorded in error'])->assertOk();
        $this->as($this->hrManager)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/reopen", ['reason' => 'The salary of one employee was wrong'])->assertOk();
    }

    public function test_the_permission_matrix_lists_the_paid_company_reopen_rule(): void
    {
        $matrix = CorrectionAuthority::matrix();

        $this->assertSame(['super_admin' => true, 'hr_manager' => false], $matrix['reopen_finalized_company_payroll_with_payments']);
        $this->assertSame(['super_admin' => true, 'hr_manager' => true], $matrix['reopen_finalized_company_payroll']);
    }
}
