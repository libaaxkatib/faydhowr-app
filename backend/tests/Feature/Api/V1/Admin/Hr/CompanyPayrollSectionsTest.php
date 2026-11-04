<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Actions\Hr\GetPayrollRollupAction;
use App\Actions\Hr\TransitionPayrollRunAction;
use App\Actions\Hr\TransitionPayrollRunSectionAction;
use App\Enums\AdminRole;
use App\Http\Controllers\Api\V1\Admin\Hr\PayrollRunController;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeePayment;
use App\Models\EmployeePenalty;
use App\Models\EmployeeWorkAssignment;
use App\Models\HrPolicy;
use App\Models\PayrollCorrection;
use App\Models\PayrollItem;
use App\Models\PayrollItemDeduction;
use App\Models\PayrollRecoveryDeduction;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Models\PayrollWorkplacePayment;
use App\Support\Hr\Payroll\ClosedPayrollCorrection;
use App\Support\Hr\Payroll\PayrollPeriodLock;
use App\Support\Hr\Payroll\PayrollPeriodView;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Independent payroll finalization per COMPANY inside one monthly run (owner decision, Option A): each company has its own section with its own
 * Draft -> Reviewed -> Finalized lifecycle (approval is payment, see CompanyPaymentApprovalTest), its own totals and payments; the month follows its sections (least advanced) and finalizes
 * itself when the last company does. Synthetic data only.
 *
 * September 2026 (30 days), everybody assigned the whole month, so a line is exactly the monthly salary:
 *   Alpha Co   : a1 300, a2 200 and the shared employee X (100)  = 600
 *   Bravo Co   : b1 400 and the shared employee X (150)          = 550
 *   Gury (guri): g1 250                                          = 250
 * X works for two companies; X's FIRST assignment (the lowest id) is the Alpha one.
 */
class CompanyPayrollSectionsTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const P = '2026-09';

    private ClientCompany $alpha;

    private ClientCompany $bravo;

    private ClientCompany $gury;

    private Employee $x;

    private EmployeeWorkAssignment $xAlpha;

    private EmployeeWorkAssignment $xBravo;

    private EmployeeWorkAssignment $a1;

    private EmployeeWorkAssignment $b1;

    private EmployeeWorkAssignment $g1;

    private Admin $superAdmin;

    private string $super;

    private int $runId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-12-20 10:00:00');
        [$this->superAdmin, $this->super] = $this->superAdminToken();

        $this->alpha = $this->makeCompany('Alpha Co');
        $this->bravo = $this->makeCompany('Bravo Co');
        $this->gury = $this->makeCompany('Gury One', 'guri');

        $this->x = $this->makeEmployee();
        $this->xAlpha = $this->makeAssignment($this->x, $this->alpha, null, 100);
        $this->xBravo = $this->makeAssignment($this->x, $this->bravo, null, 150);
        $this->a1 = $this->makeAssignment($this->makeEmployee(), $this->alpha, null, 300);
        $this->makeAssignment($this->makeEmployee(), $this->alpha, null, 200);
        $this->b1 = $this->makeAssignment($this->makeEmployee(), $this->bravo, null, 400);
        $this->g1 = $this->makeAssignment($this->makeEmployee(), $this->gury, null, 250);
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

    private function generate(): int
    {
        return $this->runId = (int) $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => self::P])->assertCreated()->json('data.id');
    }

    private function monthRun(): PayrollRun
    {
        return PayrollRun::query()->findOrFail($this->runId)->refresh();
    }

    private function section(ClientCompany $company): PayrollRunSection
    {
        return PayrollRunSection::query()->where('payroll_run_id', $this->runId)->where('client_company_id', $company->id)->firstOrFail();
    }

    /** @return array<string, string> company name => status */
    private function statuses(): array
    {
        $statuses = PayrollRunSection::query()->where('payroll_run_id', $this->runId)->with('clientCompany')->get()->mapWithKeys(fn (PayrollRunSection $s): array => [$s->clientCompany->name => $s->status])->all();
        ksort($statuses);

        return $statuses;
    }

    private function move(ClientCompany $company, string $action, array $body = [], ?string $token = null)
    {
        $section = $this->section($company);

        return $this->as($token ?? $this->super)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/{$action}", $body);
    }

    private function finalizeCompany(ClientCompany $company): void
    {
        $this->move($company, 'review')->assertOk();
        $this->move($company, 'finalize')->assertOk();
    }

    /** @return array<string, array<string, mixed>> workplaces of the month by company name */
    private function workplaces(): array
    {
        $rows = $this->as($this->super)->getJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/workplace-payments")->assertOk()->json('data.workplaces');

        return collect($rows)->keyBy('company_name')->all();
    }

    private function pay(array $workplace, array $body = [])
    {
        return $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/workplace-payments", $body + [
            'client_company_id' => $workplace['client_company_id'], 'work_location_id' => $workplace['work_location_id'], 'mode' => 'full',
            'payment_date' => '2026-12-18', 'idempotency_key' => 'key-'.bin2hex(random_bytes(6)),
        ]);
    }

    /** @return array<string, mixed> every column of every line that must never change (not section_id, not the timestamps) */
    private function linesFingerprint(?PayrollRunSection $section = null): array
    {
        return PayrollItem::query()->when($section !== null, fn ($q) => $q->where('section_id', $section->id))->orderBy('id')
            ->get(['id', 'payroll_run_id', 'period', 'employee_id', 'work_assignment_id', 'gross_cents', 'replacement_deduction_cents', 'other_deduction_cents', 'adjustment_cents', 'net_cents', 'payment_status', 'employee_payment_id'])->toArray();
    }

    // ------------------------------------------------------------------------------------------------ lifecycle

    public function test_generating_creates_one_section_per_company_with_its_own_totals(): void
    {
        $this->generate();

        $this->assertSame(['Alpha Co' => 'draft', 'Bravo Co' => 'draft', 'Gury One' => 'draft'], $this->statuses());
        $this->assertSame(1, PayrollRun::query()->count(), 'still one run per month');
        $this->assertSame([60000, 55000, 25000], [$this->section($this->alpha)->totals['net_cents'], $this->section($this->bravo)->totals['net_cents'], $this->section($this->gury)->totals['net_cents']]);
        $this->assertSame([3, 2, 1], [$this->section($this->alpha)->totals['employee_count'], $this->section($this->bravo)->totals['employee_count'], $this->section($this->gury)->totals['employee_count']]);
        $this->assertSame(0, PayrollItem::query()->whereNull('section_id')->count(), 'every line belongs to a company section');
    }

    public function test_finalizing_one_company_leaves_the_others_in_draft(): void
    {
        $this->generate();
        $this->finalizeCompany($this->alpha);

        $this->assertSame(['Alpha Co' => 'finalized', 'Bravo Co' => 'draft', 'Gury One' => 'draft'], $this->statuses());
        $alpha = $this->section($this->alpha);
        $this->assertNotNull($alpha->finalized_at);
        $this->assertSame($this->superAdmin->id, $alpha->finalized_by);
        $this->assertNull($this->section($this->bravo)->finalized_at);
    }

    public function test_reviewing_one_company_does_not_review_the_others(): void
    {
        $this->generate();
        $this->move($this->bravo, 'review')->assertOk();

        $this->assertSame(['Alpha Co' => 'draft', 'Bravo Co' => 'reviewed', 'Gury One' => 'draft'], $this->statuses());
        $this->assertSame($this->superAdmin->id, $this->section($this->bravo)->reviewed_by);
        $this->assertNull($this->section($this->alpha)->reviewed_at);
        $this->assertSame('draft', $this->monthRun()->status, 'the month is as far along as its least advanced company');
    }

    public function test_there_is_no_approve_step_for_a_company(): void
    {
        $this->generate();
        $this->move($this->gury, 'review')->assertOk();
        $this->move($this->gury, 'approve')->assertNotFound();
        $this->move($this->alpha, 'review')->assertOk();

        $this->assertSame(['Alpha Co' => 'reviewed', 'Bravo Co' => 'draft', 'Gury One' => 'reviewed'], $this->statuses());
        $this->assertNull($this->section($this->gury)->approved_at, 'approval is payment, never a button');
        $this->assertSame('draft', $this->monthRun()->status);
    }

    public function test_each_step_must_start_from_the_previous_state_of_that_company(): void
    {
        $this->generate();

        $this->move($this->alpha, 'finalize')->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_WRONG_STATE');
        $this->move($this->alpha, 'review')->assertOk();
        $this->move($this->alpha, 'review')->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_WRONG_STATE');
        $this->assertSame(['Alpha Co' => 'reviewed', 'Bravo Co' => 'draft', 'Gury One' => 'draft'], $this->statuses());
    }

    public function test_the_month_finalizes_itself_when_the_last_company_is_finalized(): void
    {
        $this->generate();
        $this->finalizeCompany($this->alpha);
        $this->finalizeCompany($this->gury);
        $this->assertSame('draft', $this->monthRun()->status, 'Bravo is still draft');
        $this->assertNull($this->monthRun()->finalized_at);

        $this->finalizeCompany($this->bravo);

        $run = $this->monthRun();
        $this->assertSame('finalized', $run->status);
        $this->assertNotNull($run->finalized_at);
        $this->assertSame($this->superAdmin->id, $run->finalized_by);
        $events = AuditLog::query()->where('entity_type', PayrollRun::class)->where('entity_id', $run->id)->get()->map(fn ($log) => $log->metadata['event'] ?? null)->all();
        $this->assertContains('payroll_run_finalized_by_sections', $events);
    }

    public function test_a_partly_finalized_month_is_not_finalized_or_locked_at_run_level_and_says_so(): void
    {
        $this->generate();
        $this->finalizeCompany($this->alpha);

        $this->assertSame('draft', $this->monthRun()->status);
        $this->assertFalse((new PayrollPeriodLock)->isLocked(self::P), 'the month as a whole is not locked');

        $month = $this->as($this->super)->getJson("/api/v1/admin/hr/payroll-runs/{$this->runId}")->assertOk()->json('data');
        $this->assertSame([true, 3, 1, true, 'draft'], [$month['sectioned'], $month['sections_total'], $month['sections_finalized'], $month['is_partially_finalized'], $month['status']]);
        $alpha = collect($month['sections'])->firstWhere('company_name', 'Alpha Co');
        $this->assertSame(['finalized', 3, '600.00', '0.00', '600.00'], [$alpha['status'], $alpha['employee_count'], $alpha['net'], $alpha['paid'], $alpha['outstanding']]);
        $this->assertNotNull($alpha['finalized_by']);

        $list = collect($this->as($this->super)->getJson('/api/v1/admin/hr/payroll-runs')->assertOk()->json('data'))->firstWhere('id', $this->runId);
        $this->assertSame([3, 1, true], [$list['sections_total'], $list['sections_finalized'], $list['is_partially_finalized']]);
    }

    public function test_the_month_level_review_moves_every_company_there_is_no_month_approve_and_finalize_stays_company_by_company(): void
    {
        $this->generate();

        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/review")->assertOk();
        $this->assertSame(['reviewed'], array_values(array_unique($this->statuses())));
        $this->assertSame('reviewed', $this->monthRun()->status);

        // There is no month-level Approve (owner decision 2026-11-04): the month is approved by itself when every company is paid in full.
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/approve")->assertNotFound();
        $this->assertSame('reviewed', $this->monthRun()->status);

        // A company is finalized straight from reviewed.
        $this->move($this->alpha, 'finalize')->assertOk();
        $this->assertSame('reviewed', $this->monthRun()->status, 'least advanced: Bravo and Gury are reviewed');
        $this->assertSame(['Alpha Co' => 'finalized', 'Bravo Co' => 'reviewed', 'Gury One' => 'reviewed'], $this->statuses());

        // The rest can only be finalized one company at a time; the month finalizes itself with the last one.
        $this->move($this->bravo, 'finalize')->assertOk();
        $this->assertSame('reviewed', $this->monthRun()->status);
        $this->move($this->gury, 'finalize')->assertOk();
        $this->assertSame('finalized', $this->monthRun()->status);
    }

    public function test_there_is_no_month_level_finalize_and_no_bulk_finalize_anywhere(): void
    {
        $this->generate();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/review")->assertOk();

        // The route is gone (not merely hidden): every method and every spelling is refused, for the highest-privileged user too, and nothing changes.
        foreach (['finalize', 'finalise', 'finalize-all', 'finalize-companies', 'bulk-finalize'] as $path) {
            foreach (['patch', 'post', 'put'] as $method) {
                $status = $this->as($this->super)->json(strtoupper($method), "/api/v1/admin/hr/payroll-runs/{$this->runId}/{$path}")->status();
                $this->assertContains($status, [404, 405], "{$method} payroll-runs/{id}/{$path} must not exist (got {$status})");
            }
        }
        $this->as($this->super)->patchJson('/api/v1/admin/hr/payroll-run-sections/finalize')->assertNotFound();
        $this->as($this->super)->patchJson('/api/v1/admin/hr/payroll-run-sections/finalize-all')->assertNotFound();
        $this->assertSame(['Alpha Co' => 'reviewed', 'Bravo Co' => 'reviewed', 'Gury One' => 'reviewed'], $this->statuses());
        $this->assertSame('reviewed', $this->monthRun()->status);

        // No registered route finalizes more than one company: the only finalize route names exactly one section.
        $finalizeRoutes = collect($this->app['router']->getRoutes()->getRoutes())->filter(fn ($route): bool => str_contains($route->uri(), 'payroll') && str_contains(strtolower($route->uri().$route->getActionName()), 'finali'));
        $this->assertSame(['api/v1/admin/hr/payroll-run-sections/{payrollRunSection}/finalize'], $finalizeRoutes->map(fn ($route): string => $route->uri())->values()->all());
        $this->assertFalse(method_exists(TransitionPayrollRunAction::class, 'finalize'), 'the month-level action is removed, not just unrouted');
        $this->assertFalse(method_exists(PayrollRunController::class, 'finalize'));

        // The shared bulk path itself refuses to finalize.
        $this->expectException(\LogicException::class);
        app(TransitionPayrollRunSectionAction::class)->bulk($this->monthRun(), 'reviewed', 'finalized', $this->superAdmin);
    }

    public function test_a_reviewed_company_can_be_reopened_with_a_reason_a_finalized_one_cannot_and_nothing_else_changes(): void
    {
        $this->generate();
        $this->move($this->alpha, 'review')->assertOk();
        $this->finalizeCompany($this->bravo);

        $this->move($this->alpha, 'reopen', ['reason' => 'short'])->assertStatus(422);
        $this->move($this->alpha, 'reopen', ['reason' => 'A salary has to be corrected first'])->assertOk();
        $this->assertSame(['Alpha Co' => 'draft', 'Bravo Co' => 'finalized', 'Gury One' => 'draft'], $this->statuses());
        $this->assertNull($this->section($this->alpha)->approved_at);

        $this->move($this->bravo, 'reopen', ['reason' => 'Trying to reopen a finalized one'])->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_NOT_REOPENABLE');
        $this->assertSame('finalized', $this->section($this->bravo)->status);
    }

    public function test_every_section_change_is_audited_with_company_period_and_both_statuses(): void
    {
        $this->generate();
        $this->finalizeCompany($this->alpha);
        $section = $this->section($this->alpha);

        $events = AuditLog::query()->where('entity_type', PayrollRunSection::class)->where('entity_id', $section->id)->get()->map(fn ($log) => $log->metadata)->filter()->values();
        $finalize = $events->firstWhere('event', 'payroll_section_finalized');
        $this->assertSame(['draft', 'reviewed'], $events->pluck('previous_status')->all());
        $this->assertSame(['reviewed', 'finalized'], $events->pluck('new_status')->all());
        $this->assertSame([self::P, 'Alpha Co', $this->alpha->id, $this->monthRun()->id], [$finalize['period'], $finalize['company'], $finalize['client_company_id'], $finalize['payroll_run_id']]);
        $this->assertSame($this->superAdmin->id, AuditLog::query()->where('entity_type', PayrollRunSection::class)->where('entity_id', $section->id)->orderByDesc('id')->first()->admin_id);
    }

    public function test_the_section_actions_use_the_existing_permissions_and_nothing_new(): void
    {
        $this->generate();
        $viewer = $this->tokenForRoleWith(AdminRole::HrEmployee, ['hr.view', 'hr.payroll.view']);
        $preparer = $this->tokenForRoleWith(AdminRole::HrEmployee, ['hr.view', 'hr.payroll.view', 'hr.payroll.manage']);
        $manager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.payroll.view', 'hr.payroll.manage', 'hr.payroll.approve']);

        $this->move($this->alpha, 'review', [], $viewer)->assertForbidden();
        $this->move($this->alpha, 'review', [], $preparer)->assertOk();
        $this->move($this->alpha, 'finalize', [], $preparer)->assertForbidden();
        $this->move($this->alpha, 'finalize', [], $viewer)->assertForbidden();
        $this->move($this->alpha, 'finalize', [], $manager)->assertOk();
        $this->assertSame('finalized', $this->section($this->alpha)->status);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->patchJson('/api/v1/admin/hr/payroll-run-sections/'.$this->section($this->bravo)->id.'/review')->assertUnauthorized();
        $this->assertSame('draft', $this->section($this->bravo)->status);
    }

    // ------------------------------------------------------------------------------------------------ generation

    public function test_generate_never_overwrites_a_finalized_company_and_still_refreshes_the_others(): void
    {
        $this->generate();
        $this->finalizeCompany($this->alpha);
        $alphaBefore = $this->linesFingerprint($this->section($this->alpha));
        $alphaTotals = $this->section($this->alpha)->totals;
        $alphaStamp = $this->section($this->alpha)->finalized_at;

        // The inputs change after Alpha was finalized: Alpha's salary AND Bravo's.
        $this->a1->update(['salary_amount' => 999]);
        $this->a1->salaryHistories()->update(['amount' => 999]);
        $this->b1->update(['salary_amount' => 500]);
        $this->b1->salaryHistories()->update(['amount' => 500]);

        $this->generate();

        $this->assertSame($alphaBefore, $this->linesFingerprint($this->section($this->alpha)), 'the finalized company keeps its lines, ids and amounts exactly');
        $this->assertSame($alphaTotals, $this->section($this->alpha)->totals);
        $this->assertSame('finalized', $this->section($this->alpha)->status);
        $this->assertEquals($alphaStamp, $this->section($this->alpha)->finalized_at);
        // Bravo was rebuilt from the new salary; the month's totals are what is stored.
        $this->assertSame(50000, (int) PayrollItem::query()->where('work_assignment_id', $this->b1->id)->value('net_cents'));
        $this->assertSame(60000 + 65000 + 25000, (int) $this->monthRun()->totals['net_cents'], 'stored lines: Alpha as finalized, Bravo and Gury as regenerated');
        $this->assertTrue(collect($this->monthRun()->totals['warnings'])->contains(fn (string $w): bool => str_contains($w, 'kept exactly as stored') && str_contains($w, 'Alpha Co')), 'HR is told the live calculation differs');
    }

    public function test_generate_refuses_a_month_whose_every_company_is_finalized(): void
    {
        $this->generate();

        foreach ([$this->alpha, $this->bravo, $this->gury] as $company) {
            $this->finalizeCompany($company);
        }

        $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => self::P])->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_LOCKED');
    }

    public function test_a_reviewed_company_goes_back_to_draft_when_the_month_is_regenerated_as_it_always_did(): void
    {
        $this->generate();
        $this->move($this->bravo, 'review')->assertOk();
        $this->generate();

        $this->assertSame(['draft'], array_values(array_unique($this->statuses())));
        $this->assertNull($this->section($this->bravo)->reviewed_at);
    }

    // ------------------------------------------------------------------------------------------------ cross-company employee

    public function test_a_shared_employees_penalty_and_advance_each_hit_only_their_own_company_even_after_the_other_is_finalized(): void
    {
        // X works for Alpha (100.00) and Bravo (150.00). The penalty is Alpha's, the advance is Bravo's: each reduces ONLY its own company's pay.
        EmployeePenalty::query()->create(['employee_id' => $this->x->id, 'client_company_id' => $this->alpha->id, 'penalty_date' => '2026-09-05', 'reason' => 'Late', 'deduction_amount' => 15, 'currency' => 'USD', 'payroll_period' => self::P]);
        EmployeeAdvance::query()->create(['employee_id' => $this->x->id, 'client_company_id' => $this->bravo->id, 'advance_date' => '2026-09-06', 'amount' => 20, 'currency' => 'USD', 'payroll_period' => self::P, 'reason' => 'Rent']);
        $this->generate();

        $deductions = fn () => PayrollItemDeduction::query()->whereIn('kind', ['penalty', 'advance'])->get();
        $lineOf = fn (EmployeeWorkAssignment $a) => PayrollItem::query()->where('work_assignment_id', $a->id)->firstOrFail();
        $kindsOn = fn (EmployeeWorkAssignment $a) => PayrollItemDeduction::query()->where('payroll_item_id', $lineOf($a)->id)->whereIn('kind', ['penalty', 'advance'])->pluck('kind')->all();
        $this->assertCount(2, $deductions(), 'one penalty and one advance in total');
        $this->assertSame(10000 - 1500, (int) $lineOf($this->xAlpha)->net_cents, "Alpha's penalty reduces Alpha's pay only");
        $this->assertSame(15000 - 2000, (int) $lineOf($this->xBravo)->net_cents, "Bravo's advance reduces Bravo's pay only");
        $this->assertSame(['penalty'], $kindsOn($this->xAlpha));
        $this->assertSame(['advance'], $kindsOn($this->xBravo));

        // Alpha is finalized; Bravo is regenerated around it (twice): nothing is added again and Alpha's finalized line never moves.
        $this->finalizeCompany($this->alpha);
        $alphaLine = $lineOf($this->xAlpha);
        $alphaStamp = [$alphaLine->id, (int) $alphaLine->net_cents, $alphaLine->updated_at->toIso8601String()];
        $this->b1->update(['salary_amount' => 450]);
        $this->b1->salaryHistories()->update(['amount' => 450]);
        $this->generate();
        $this->generate();

        $this->assertCount(2, $deductions(), 'regenerating Bravo around a finalized Alpha never adds them again');
        $this->assertSame($alphaStamp, [$lineOf($this->xAlpha)->id, (int) $lineOf($this->xAlpha)->net_cents, $lineOf($this->xAlpha)->updated_at->toIso8601String()]);
        $this->assertSame(15000 - 2000, (int) $lineOf($this->xBravo)->net_cents);
        $this->assertSame(['advance'], $kindsOn($this->xBravo));
        $this->assertSame(1, PayrollItem::query()->where('work_assignment_id', $this->xAlpha->id)->count(), 'no duplicate payroll line');
    }

    public function test_a_shared_employees_unowned_penalty_is_charged_to_neither_company_and_is_reported(): void
    {
        EmployeePenalty::query()->create(['employee_id' => $this->x->id, 'penalty_date' => '2026-09-05', 'reason' => 'Late', 'deduction_amount' => 15, 'currency' => 'USD', 'payroll_period' => self::P]);
        $run = $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => self::P])->assertCreated();

        $this->assertSame(0, PayrollItemDeduction::query()->where('kind', 'penalty')->count(), 'not guessed onto the first line');
        $this->assertSame([10000, 15000], PayrollItem::query()->whereIn('work_assignment_id', [$this->xAlpha->id, $this->xBravo->id])->orderBy('work_assignment_id')->pluck('net_cents')->map(fn ($c) => (int) $c)->all());
        $this->assertStringContainsString('names no company', implode(' ', $run->json('data.warnings')));
    }

    public function test_an_overpayment_is_recovered_only_from_the_company_that_was_overpaid(): void
    {
        // X was overpaid 120.00 by ALPHA in August (a closed month). September recovers it from Alpha's line only (100.00 of it - Alpha's pay that month);
        // Bravo's payroll is never touched by Alpha's overpayment.
        $august = PayrollRun::query()->create(['period' => '2026-08', 'status' => 'finalized', 'currency' => 'USD', 'totals' => ['item_count' => 0, 'net_cents' => 0]]);
        $correction = PayrollCorrection::query()->create([
            'batch_key' => (string) Str::uuid(), 'employee_id' => $this->x->id, 'client_company_id' => $this->alpha->id, 'payroll_run_id' => $august->id, 'period' => '2026-08', 'kind' => 'overpayment', 'action' => 'edited',
            'recorded_net_cents' => 25000, 'corrected_net_cents' => 13000, 'difference_cents' => 12000, 'outstanding_cents' => 12000, 'status' => 'open', 'reason' => 'Paid too much in August',
        ]);
        $this->generate();

        $recovery = fn () => PayrollItemDeduction::query()->where('kind', 'overpayment_recovery')->where('source_id', $correction->id);
        $lineOf = fn (EmployeeWorkAssignment $a) => PayrollItem::query()->where('work_assignment_id', $a->id)->firstOrFail();
        $this->assertSame([1, 10000], [$recovery()->count(), (int) $recovery()->sum('amount_cents')], 'limited to what Alpha pays this month');
        $this->assertSame([0, 15000], [(int) $lineOf($this->xAlpha)->net_cents, (int) $lineOf($this->xBravo)->net_cents], "Bravo's pay is untouched");
        $ledger = fn () => PayrollRecoveryDeduction::query()->where('payroll_correction_id', $correction->id)->get();
        $this->assertSame([1, 10000, 'scheduled'], [$ledger()->count(), (int) $ledger()->first()->amount_cents, $ledger()->first()->status]);

        // Bravo is finalized first: the recovery is not Bravo's, so it stays scheduled and Bravo's line is unchanged.
        $this->finalizeCompany($this->bravo);
        $this->assertSame('scheduled', $ledger()->first()->status);

        // Alpha (the only line carrying it) is finalized: the recovery counts as recovered (applied) - one row.
        $this->finalizeCompany($this->alpha);
        $this->assertSame([1, 10000, 'applied'], [$ledger()->count(), (int) $ledger()->first()->amount_cents, $ledger()->first()->status]);
        $this->assertSame([1, 10000], [$recovery()->count(), (int) $recovery()->sum('amount_cents')]);
    }

    public function test_an_overpayment_that_names_no_company_is_not_guessed_when_the_employee_works_for_two(): void
    {
        $august = PayrollRun::query()->create(['period' => '2026-08', 'status' => 'finalized', 'currency' => 'USD', 'totals' => ['item_count' => 0, 'net_cents' => 0]]);
        PayrollCorrection::query()->create([
            'batch_key' => (string) Str::uuid(), 'employee_id' => $this->x->id, 'payroll_run_id' => $august->id, 'period' => '2026-08', 'kind' => 'overpayment', 'action' => 'edited',
            'recorded_net_cents' => 25000, 'corrected_net_cents' => 13000, 'difference_cents' => 12000, 'outstanding_cents' => 12000, 'status' => 'open', 'reason' => 'Paid too much in August',
        ]);
        $this->generate();

        $this->assertSame(0, PayrollItemDeduction::query()->where('kind', 'overpayment_recovery')->count());
        $this->assertSame([10000, 15000], PayrollItem::query()->whereIn('work_assignment_id', [$this->xAlpha->id, $this->xBravo->id])->orderBy('work_assignment_id')->pluck('net_cents')->map(fn ($c) => (int) $c)->all());
    }

    public function test_a_shared_employees_line_cannot_be_paid_twice_through_the_line_and_the_company(): void
    {
        $this->generate();
        $this->finalizeCompany($this->alpha);
        $line = PayrollItem::query()->where('work_assignment_id', $this->xAlpha->id)->firstOrFail();

        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$line->id}/pay", [])->assertOk();
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$line->id}/pay", [])->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_ITEM_ALREADY_PAID');

        $alpha = $this->workplaces()['Alpha Co'];
        $this->assertSame([60000, 10000, 50000], [$alpha['total_cents'], $alpha['paid_cents'], $alpha['outstanding_cents']]);
        $this->pay($alpha, ['mode' => 'full'])->assertCreated()->assertJsonPath('data.workplace.status', 'paid');
        $this->assertSame(50000, (int) PayrollWorkplacePayment::query()->sum('amount_cents'), 'the company payment covers only what the line payment had not');

        // X's Bravo line belongs to an unfinalized company: it cannot be paid by anyone.
        $bravoLine = PayrollItem::query()->where('work_assignment_id', $this->xBravo->id)->firstOrFail();
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$bravoLine->id}/pay", [])->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_NOT_FINALIZED');
        $this->assertSame(1, EmployeePayment::query()->where('employee_id', $this->x->id)->count());
    }

    // ------------------------------------------------------------------------------------------------ payments

    public function test_full_and_partial_payments_work_for_a_finalized_company_and_are_refused_for_the_others(): void
    {
        $this->generate();
        $this->finalizeCompany($this->alpha);
        $w = $this->workplaces();

        $this->assertSame([true, false, false], [$w['Alpha Co']['can_record'], $w['Bravo Co']['can_record'], $w['Gury One']['can_record']]);

        $this->pay($w['Alpha Co'], ['mode' => 'partial', 'amount' => '100.50'])->assertCreated()->assertJsonPath('data.workplace.status', 'partial');
        $this->pay($w['Alpha Co'], ['mode' => 'full'])->assertCreated()->assertJsonPath('data.workplace.status', 'paid')->assertJsonPath('data.workplace.outstanding', '0.00');

        $this->pay($w['Bravo Co'])->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_NOT_FINALIZED')->assertJsonFragment(['message' => 'This company\'s payroll is not finalized yet. Payments can only be recorded for a finalized company.']);
        $this->pay($w['Gury One'], ['mode' => 'partial', 'amount' => '10'])->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_NOT_FINALIZED');
        $this->assertSame(2, PayrollWorkplacePayment::query()->count());

        $after = $this->workplaces();
        $this->assertSame(['paid', 'unpaid', 'unpaid'], [$after['Alpha Co']['status'], $after['Bravo Co']['status'], $after['Gury One']['status']]);
        $this->assertSame([0, 0], [$after['Bravo Co']['paid_cents'], $after['Gury One']['paid_cents']]);
        $this->assertSame([55000, 25000], [$after['Bravo Co']['outstanding_cents'], $after['Gury One']['outstanding_cents']]);
        $month = $this->as($this->super)->getJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/workplace-payments")->json('data');
        $this->assertTrue($month['can_record'], 'a partly finalized month does not stop the companies that are finalized');
        $alphaSection = collect($this->as($this->super)->getJson("/api/v1/admin/hr/payroll-runs/{$this->runId}")->json('data.sections'))->firstWhere('company_name', 'Alpha Co');
        $this->assertSame(['600.00', '0.00'], [$alphaSection['paid'], $alphaSection['outstanding']]);
    }

    public function test_overpayment_idempotency_and_void_still_work_per_company_in_a_partly_finalized_month(): void
    {
        $this->generate();
        $this->finalizeCompany($this->gury);
        $gury = $this->workplaces()['Gury One'];
        $key = 'retry-key-0001';

        $first = $this->pay($gury, ['mode' => 'partial', 'amount' => '100', 'idempotency_key' => $key])->assertCreated();
        $this->pay($gury, ['mode' => 'partial', 'amount' => '100', 'idempotency_key' => $key])->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, PayrollWorkplacePayment::query()->count());
        $this->pay($gury, ['mode' => 'partial', 'amount' => '150.01'])->assertStatus(422)->assertJsonPath('error_code', 'AMOUNT_EXCEEDS_OUTSTANDING');

        $id = (int) $first->json('data.payment_id');
        $this->as($this->super)->postJson("/api/v1/admin/hr/workplace-payments/{$id}/void", ['reason' => 'Typed by mistake'])->assertOk()->assertJsonPath('data.workplace.status', 'unpaid');
        $this->assertSame('unpaid', $this->workplaces()['Gury One']['status']);
    }

    public function test_a_line_can_be_marked_paid_only_when_its_own_company_is_finalized_and_voiding_follows_the_same_rule(): void
    {
        $this->generate();
        $this->finalizeCompany($this->gury);
        $guryLine = PayrollItem::query()->where('work_assignment_id', $this->g1->id)->firstOrFail();
        $bravoLine = PayrollItem::query()->where('work_assignment_id', $this->b1->id)->firstOrFail();

        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$bravoLine->id}/pay", [])->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_NOT_FINALIZED');
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$guryLine->id}/pay", [])->assertOk();
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$bravoLine->id}/void-payment", ['reason' => 'Nothing was paid here'])->assertStatus(422);
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$guryLine->id}/void-payment", ['reason' => 'Paid to the wrong account'])->assertOk();
    }

    // ------------------------------------------------------------------------------------------------ closed payroll correction

    public function test_a_change_that_reaches_a_finalized_company_is_a_closed_payroll_correction_and_other_companies_are_untouched(): void
    {
        $this->generate();
        $this->finalizeCompany($this->alpha);
        $bravoBefore = $this->linesFingerprint($this->section($this->bravo));
        $alphaBefore = $this->linesFingerprint($this->section($this->alpha));

        // Alpha's employee started later than recorded: it reaches the finalized company -> a correction, Super Admin only, with a reason.
        $hrEditor = $this->tokenWithOnly(['hr.view', 'hr.manage']);
        $this->as($hrEditor)->putJson("/api/v1/admin/hr/work-assignments/{$this->a1->id}", ['start_date' => '2026-09-21', 'correction_reason' => 'Started later'])->assertForbidden()->assertJsonPath('error_code', 'PAYROLL_CORRECTION_SUPER_ADMIN_ONLY');
        $this->as($this->super)->putJson("/api/v1/admin/hr/work-assignments/{$this->a1->id}", ['start_date' => '2026-09-21'])->assertUnprocessable()->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');
        $this->as($this->super)->putJson("/api/v1/admin/hr/work-assignments/{$this->a1->id}", ['start_date' => '2026-09-21', 'correction_reason' => 'The employee actually started on 21 September'])->assertOk();

        $correction = PayrollCorrection::query()->firstOrFail();
        $this->assertSame('overpayment', $correction->kind);
        $this->assertSame($this->a1->employee_id, $correction->employee_id);
        $this->assertSame($this->section($this->alpha)->id, PayrollItem::query()->findOrFail($correction->payroll_item_id)->section_id, 'the correction hangs on the finalized company\'s own line');
        $log = AuditLog::query()->where('entity_type', PayrollCorrection::class)->where('entity_id', $correction->id)->firstOrFail();
        $this->assertSame([$this->alpha->id], $log->metadata['client_company_ids']);
        $this->assertSame(self::P, $log->metadata['period']);

        $this->assertSame($alphaBefore, $this->linesFingerprint($this->section($this->alpha)), 'the finalized lines are never edited');
        $this->assertSame($bravoBefore, $this->linesFingerprint($this->section($this->bravo)), 'another company is not touched');
        $this->assertSame(['Alpha Co' => 'finalized', 'Bravo Co' => 'draft', 'Gury One' => 'draft'], $this->statuses());
    }

    public function test_a_correction_for_a_shared_employee_belongs_to_one_company_and_is_measured_on_that_companys_line_only(): void
    {
        // X works for Alpha (100.00) and Bravo (150.00); both are finalized. Alpha's assignment started later than recorded: the correction is about
        // ALPHA's pay (100.00 recorded) and carries Alpha's id - it never includes Bravo's 150.00 and it is not recovered from Bravo.
        $this->generate();
        $this->finalizeCompany($this->alpha);
        $this->finalizeCompany($this->bravo);
        $bravoBefore = $this->linesFingerprint($this->section($this->bravo));
        // Editing an assignment of someone who works for two companies is only allowed while the Super Admin's multiple-company policy is on.
        $this->as($this->super)->putJson('/api/v1/admin/hr/policies/'.HrPolicy::MULTI_COMPANY_ASSIGNMENTS, ['enabled' => true, 'reason' => 'A cleaner works two sites'])->assertOk();

        $response = $this->as($this->super)->putJson("/api/v1/admin/hr/work-assignments/{$this->xAlpha->id}", ['start_date' => '2026-09-16', 'correction_reason' => 'Started on 16 September']);
        $this->assertSame(200, $response->status(), $response->getContent());

        $correction = PayrollCorrection::query()->where('employee_id', $this->x->id)->sole();
        $this->assertSame($this->alpha->id, $correction->client_company_id);
        $this->assertSame([10000, 5000, 'overpayment'], [(int) $correction->recorded_net_cents, (int) $correction->corrected_net_cents, $correction->kind], "Alpha's line only");
        $this->assertSame(5000, (int) $correction->outstanding_cents);
        $this->assertSame($bravoBefore, $this->linesFingerprint($this->section($this->bravo)));
        $log = AuditLog::query()->where('entity_type', PayrollCorrection::class)->where('entity_id', $correction->id)->firstOrFail();
        $this->assertSame($this->alpha->id, $log->metadata['client_company_id']);
    }

    public function test_a_company_with_a_shared_employees_unowned_record_cannot_be_finalized_until_the_record_is_assigned(): void
    {
        // A penalty from before company ownership names no company, and X works for two: nothing is charged and neither company may be locked in.
        $penalty = EmployeePenalty::query()->create(['employee_id' => $this->x->id, 'penalty_date' => '2026-09-05', 'reason' => 'Late', 'deduction_amount' => 15, 'currency' => 'USD', 'payroll_period' => self::P]);
        $this->generate();
        $this->move($this->alpha, 'review')->assertOk();

        $this->move($this->alpha, 'finalize')->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_AMBIGUOUS_COMPANY_INPUT')
            ->assertJsonPath('errors.unowned_inputs.0.kind', 'penalty')->assertJsonPath('errors.unowned_inputs.0.id', $penalty->id);
        $this->assertSame('reviewed', $this->section($this->alpha)->status);

        // A company that does not employ X is not blocked by it.
        $this->move($this->gury, 'review')->assertOk();
        $this->move($this->gury, 'finalize')->assertOk();

        // HR assigns the penalty to Bravo and generates again: it is now charged to Bravo only, and Alpha can be finalized.
        $penalty->update(['client_company_id' => $this->bravo->id]);
        $this->generate();
        $this->move($this->alpha, 'review')->assertOk();
        $this->move($this->alpha, 'finalize')->assertOk();
        $this->assertSame(15000 - 1500, (int) PayrollItem::query()->where('work_assignment_id', $this->xBravo->id)->value('net_cents'));
        $this->assertSame(10000, (int) PayrollItem::query()->where('work_assignment_id', $this->xAlpha->id)->value('net_cents'));
    }

    public function test_a_change_that_only_reaches_a_company_that_is_still_open_needs_no_correction(): void
    {
        $this->generate();
        $this->finalizeCompany($this->alpha);
        $hrEditor = $this->tokenWithOnly(['hr.view', 'hr.manage']);

        $this->as($hrEditor)->putJson("/api/v1/admin/hr/work-assignments/{$this->b1->id}", ['start_date' => '2026-09-21'])->assertOk();

        $this->assertSame(0, PayrollCorrection::query()->count());
        $this->assertSame('2026-09-21', $this->b1->fresh()->start_date->toDateString());
    }

    public function test_a_new_assignment_in_a_finalized_company_goes_through_the_correction_flow_and_never_reopens_the_section(): void
    {
        $this->generate();
        $this->finalizeCompany($this->alpha);
        $newcomer = $this->makeEmployee();
        $hrManager = Admin::factory()->create(['role' => AdminRole::HrManager]);
        $correct = fn (Admin $actor, ?string $reason) => app(ClosedPayrollCorrection::class)->run($actor, 'assignment_created', [$newcomer->id], '2026-09-01', $reason, [], fn () => $this->makeAssignment($newcomer, $this->alpha, null, 210));

        try {
            $correct($hrManager, 'A new person started');
            $this->fail('A non-Super Admin must be refused.');
        } catch (HttpResponseException $e) {
            $this->assertSame('PAYROLL_CORRECTION_SUPER_ADMIN_ONLY', $e->getResponse()->getData(true)['error_code']);
        }
        $this->assertSame(0, EmployeeWorkAssignment::query()->where('employee_id', $newcomer->id)->count(), 'rolled back');

        $correct($this->superAdmin, 'A new person started on the first of September');
        $this->assertSame(1, PayrollCorrection::query()->where('employee_id', $newcomer->id)->count());
        $this->assertSame('underpayment', PayrollCorrection::query()->where('employee_id', $newcomer->id)->value('kind'));
        $this->assertSame('finalized', $this->section($this->alpha)->status, 'the finalized section is not reopened');
        $this->assertSame(0, PayrollItem::query()->where('employee_id', $newcomer->id)->count(), 'no line is added to a finalized company');
    }

    // ------------------------------------------------------------------------------------------------ readers

    public function test_the_rollup_shows_each_companys_own_status_and_the_month_progress(): void
    {
        $this->generate();
        $this->finalizeCompany($this->alpha);

        $result = app(GetPayrollRollupAction::class)->handle(self::P);
        $rows = $result['rows']->keyBy('work_assignment_id');

        $this->assertSame([3, 1, 'mixed', true, 'draft'], [$result['meta']['companies_total'], $result['meta']['companies_finalized'], $result['meta']['source'], $result['meta']['is_estimate'], $result['meta']['run_status']]);
        $this->assertSame([false, 'finalized'], [$rows[$this->a1->id]['is_estimate'], $rows[$this->a1->id]['run_status']], 'Alpha: its stored, finalized amount');
        $this->assertSame([true, 'draft'], [$rows[$this->b1->id]['is_estimate'] ?? null, $rows[$this->b1->id]['run_status'] ?? null], 'Bravo: still an estimate');
        $this->assertSame('1,400.00', number_format((float) $result['meta']['totals']['net_payable'], 2), 'every company is in the total: stored for Alpha, estimate for the others');
    }

    public function test_the_month_view_serves_stored_lines_for_finalized_companies_and_estimates_for_the_rest(): void
    {
        $this->generate();
        $this->finalizeCompany($this->alpha);
        $this->a1->update(['salary_amount' => 999]);
        $this->a1->salaryHistories()->update(['amount' => 999]);
        $this->b1->update(['salary_amount' => 500]);
        $this->b1->salaryHistories()->update(['amount' => 500]);

        $view = app(PayrollPeriodView::class)->forPeriod(self::P);
        $lines = collect($view['lines'])->keyBy('work_assignment_id');

        $this->assertSame(PayrollPeriodView::SOURCE_MIXED, $view['source']);
        $this->assertSame([PayrollPeriodView::LINE_STORED, 30000, true], [$lines[$this->a1->id]['line_source'], $lines[$this->a1->id]['net_cents'], $lines[$this->a1->id]['payment_recorded']], 'Alpha stays as finalized, whatever the live data says now');
        $this->assertSame([PayrollPeriodView::LINE_ESTIMATE, 50000, false], [$lines[$this->b1->id]['line_source'], $lines[$this->b1->id]['net_cents'], $lines[$this->b1->id]['payment_recorded']]);
        $this->assertCount(6, $view['lines'], 'every assignment appears exactly once');
    }

    // ------------------------------------------------------------------------------------------------ excel

    private function preview(array $query = []): array
    {
        return $this->as($this->super)->getJson('/api/v1/admin/hr/reports/monthly-payment-list/workbook-preview?'.http_build_query($query + ['period' => self::P, 'scope' => 'all']))->assertOk()->json('data');
    }

    /** @return array<string, string> the label => value of the Summary sheet's first facts (column A => column B) */
    private function summaryFacts(array $preview): array
    {
        $sheet = collect($preview['sheets'])->firstWhere('name', 'Summary');
        $facts = [];

        foreach ($sheet['rows'] as $row) {
            if (isset($row[0], $row[1])) {
                $facts[$row[0][0]] = $row[1][0];
            }
        }

        return $facts;
    }

    public function test_the_excel_preview_says_mixed_for_a_month_with_open_companies_and_not_estimate_for_a_finalized_company(): void
    {
        $this->generate();
        $this->finalizeCompany($this->alpha);

        $all = $this->preview();
        $this->assertTrue($all['is_estimate']);
        $this->assertStringContainsString('MIXED', $this->summaryFacts($all)['Amounts based on']);
        $this->assertStringContainsString('ESTIMATE', $this->summaryFacts($all)['Amounts based on']);
        $list = collect($all['sheets'])->firstWhere('name', 'Payment list');
        $this->assertStringContainsString('ESTIMATE', $list['rows'][0][0][0], 'the title says ESTIMATE while any included company is not finalized');

        $alpha = $this->preview(['scope' => 'company', 'client_company_id' => $this->alpha->id]);
        $this->assertFalse($alpha['is_estimate'], 'one finalized company is shown with its finalized figures');
        $this->assertStringContainsString('stored amounts', $this->summaryFacts($alpha)['Amounts based on']);
        $alphaList = collect($alpha['sheets'])->firstWhere('name', 'Payment list');
        $this->assertStringNotContainsString('ESTIMATE', $alphaList['rows'][0][0][0]);

        $bravo = $this->preview(['scope' => 'company', 'client_company_id' => $this->bravo->id]);
        $this->assertTrue($bravo['is_estimate']);
        $this->assertStringContainsString('ESTIMATE', $this->summaryFacts($bravo)['Amounts based on']);
    }

    public function test_the_excel_payment_status_follows_each_companys_own_state_and_the_structure_is_unchanged(): void
    {
        $this->generate();
        $this->finalizeCompany($this->alpha);
        $line = PayrollItem::query()->where('work_assignment_id', $this->a1->id)->firstOrFail();
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$line->id}/pay", [])->assertOk();

        $preview = $this->preview();
        $employees = collect($preview['sheets'])->firstWhere('name', 'Employees');
        $header = array_map(fn ($c) => $c[0], $employees['rows'][3]);
        $statusColumn = array_search('Payment status', $header, true);
        $numberColumn = array_search('Employee number', $header, true);
        $byNumber = [];

        foreach (array_slice($employees['rows'], 4) as $row) {
            if (isset($row[$numberColumn]) && str_starts_with($row[$numberColumn][0], 'EMP-')) {
                $byNumber[$row[$numberColumn][0]] = $row[$statusColumn][0] ?? '';
            }
        }

        $paidEmployee = $this->a1->employee->employee_number;
        $this->assertSame('Paid', $byNumber[$paidEmployee]);
        $this->assertSame('Not recorded (payroll not finalized)', $byNumber[$this->b1->employee->employee_number]);
        $this->assertSame('Not recorded (payroll not finalized)', $byNumber[$this->g1->employee->employee_number]);
        // X works for both: the row is "recorded" only if EVERY line is in a finalized company.
        $this->assertSame('Not recorded (payroll not finalized)', $byNumber[$this->x->employee_number]);

        $this->assertSame(['Summary', 'Payment list', 'Employees', 'Not in payroll'], array_column($preview['sheets'], 'name'));
        $this->assertCount(21, collect($preview['sheets'])->firstWhere('name', 'Employees')['headers']);
        $this->assertCount(10, collect($preview['sheets'])->firstWhere('name', 'Payment list')['headers']);
    }

    public function test_the_preview_download_match_and_hash_protection_hold_for_a_mixed_month(): void
    {
        $this->generate();
        $this->finalizeCompany($this->alpha);
        $preview = $this->preview();
        $query = http_build_query(['period' => self::P, 'scope' => 'all']);

        $this->as($this->super)->get('/api/v1/admin/hr/reports/monthly-payment-list?'.$query.'&preview_hash='.$preview['content_hash'].'&generated_at='.urlencode($preview['generated_at']))->assertOk();
        $this->as($this->super)->get('/api/v1/admin/hr/reports/monthly-payment-list?'.$query.'&preview_hash='.str_repeat('a', 64).'&generated_at='.urlencode($preview['generated_at']))->assertStatus(409)->assertJsonPath('error_code', 'PREVIEW_OUT_OF_DATE');

        // Finalizing another company changes the workbook: the old preview is refused.
        $this->finalizeCompany($this->bravo);
        $this->as($this->super)->get('/api/v1/admin/hr/reports/monthly-payment-list?'.$query.'&preview_hash='.$preview['content_hash'].'&generated_at='.urlencode($preview['generated_at']))->assertStatus(409)->assertJsonPath('error_code', 'PREVIEW_OUT_OF_DATE');
    }

    // ------------------------------------------------------------------------------------------------ legacy runs and the database

    public function test_a_run_without_sections_keeps_its_run_level_behaviour(): void
    {
        // A month created before company sections existed: a finalized run whose lines have no section.
        $run = PayrollRun::query()->create(['period' => '2026-08', 'status' => 'finalized', 'currency' => 'USD', 'finalized_at' => now(), 'finalized_by' => $this->superAdmin->id, 'totals' => ['item_count' => 1, 'net_cents' => 30000]]);
        $item = PayrollItem::query()->create([
            'payroll_run_id' => $run->id, 'period' => '2026-08', 'employee_id' => $this->a1->employee_id, 'work_assignment_id' => $this->a1->id, 'employee_name' => 'Legacy', 'employee_number' => 'EMP-1',
            'company_name' => 'Alpha Co', 'eligible_days' => 31, 'days_in_month' => 31, 'gross_cents' => 30000, 'net_cents' => 30000, 'calc_snapshot' => [],
        ]);

        $this->assertNull($item->section_id);
        $this->assertSame(0, $run->sections()->count());
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$item->id}/pay", [])->assertOk();
        $workplaces = collect($this->as($this->super)->getJson("/api/v1/admin/hr/payroll-runs/{$run->id}/workplace-payments")->assertOk()->json('data.workplaces'));
        $this->assertTrue($workplaces->first()['can_record'], 'a finalized run without sections is payable as before');
        $this->assertTrue((new PayrollPeriodLock)->isLocked('2026-08'), 'locked month-wide, as before');
        $view = app(PayrollPeriodView::class)->forPeriod('2026-08');
        $this->assertSame([PayrollPeriodView::SOURCE_STORED, false], [$view['source'], $view['is_estimate']]);
        $month = $this->as($this->super)->getJson("/api/v1/admin/hr/payroll-runs/{$run->id}")->json('data');
        $this->assertSame([false, 0, false], [$month['sectioned'], $month['sections_total'], $month['is_partially_finalized']]);
    }

    public function test_a_draft_run_without_sections_still_moves_as_a_whole(): void
    {
        $run = PayrollRun::query()->create(['period' => '2026-08', 'status' => 'draft', 'currency' => 'USD', 'totals' => ['item_count' => 1, 'net_cents' => 30000]]);
        PayrollItem::query()->create([
            'payroll_run_id' => $run->id, 'period' => '2026-08', 'employee_id' => $this->a1->employee_id, 'work_assignment_id' => $this->a1->id, 'employee_name' => 'Legacy', 'employee_number' => 'EMP-1',
            'company_name' => 'Alpha Co', 'eligible_days' => 31, 'days_in_month' => 31, 'gross_cents' => 30000, 'net_cents' => 30000, 'calc_snapshot' => [],
        ]);

        // Reviewing the month still works for a run that has no sections yet; there is no month-level Approve or Finalize to go on with.
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/review")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/approve")->assertNotFound();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/finalize")->assertNotFound();

        $this->assertSame('reviewed', $run->fresh()->status);
        $this->assertSame(0, PayrollRunSection::query()->where('payroll_run_id', $run->id)->count());
    }

    public function test_the_database_refuses_a_second_no_company_section_and_a_bad_status_on_postgresql(): void
    {
        $this->generate();

        $row = ['payroll_run_id' => $this->runId, 'client_company_id' => null, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()];
        DB::table('payroll_run_sections')->insert($row);

        foreach ([$row, ['client_company_id' => $this->alpha->id] + $row, ['status' => 'finished', 'client_company_id' => $this->bravo->id] + $row] as $bad) {
            if (DB::getDriverName() !== 'pgsql' && ($bad['status'] ?? '') === 'finished') {
                continue;
            }

            DB::beginTransaction();

            try {
                DB::table('payroll_run_sections')->insert($bad);
                $this->fail('The database accepted an invalid section.');
            } catch (QueryException) {
                $this->assertTrue(true);
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_a_month_of_many_companies_is_shown_with_a_fixed_number_of_queries(): void
    {
        $this->generate();
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->as($this->super)->getJson("/api/v1/admin/hr/payroll-runs/{$this->runId}")->assertOk();
            $this->as($this->super)->getJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/workplace-payments")->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $few = $count();

        for ($i = 0; $i < 40; $i++) {
            $this->makeAssignment($this->makeEmployee(), $this->makeCompany('Extra Co '.$i), null, 120 + $i);
        }

        $this->generate();
        $many = $count();

        $this->assertSame(43, PayrollRunSection::query()->where('payroll_run_id', $this->runId)->count());
        $this->assertLessThanOrEqual($few + 6, $many, "{$few} queries for 3 companies, {$many} for 43: it must not grow with the number of companies");
    }
}
