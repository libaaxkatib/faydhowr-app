<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Actions\Hr\TransitionPayrollRunSectionAction;
use App\Enums\AdminRole;
use App\Http\Controllers\Api\V1\Admin\Hr\PayrollRunController;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Support\Hr\Payroll\PayrollSections;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FinalizesCompanies;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decision O-1 (Option A, 2026-11-05): the month-level Reopen is gone. It moved EVERY reviewed / legacy-approved company back to draft in one call
 * and cleared the approval stamps. A company is reopened only through its own section, with its reason, its authorization and its audit entry.
 * Synthetic data only.
 */
class MonthLevelReopenRemovedTest extends TestCase
{
    use FinalizesCompanies;
    use HrFixtures;
    use RefreshDatabase;

    private const P = '2026-11';

    private string $super;

    private int $superId;

    private string $hr;

    /** @var array<string, ClientCompany> */
    private array $co = [];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-05 10:00:00');
        [$admin, $this->super] = $this->superAdminToken();
        $this->superId = $admin->id; // the approval stamp names a real admin (a foreign key on PostgreSQL)
        $this->hr = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage', 'hr.payroll.view', 'hr.payroll.manage', 'hr.payroll.approve']);

        foreach (['A', 'B', 'C', 'D'] as $n) {
            $this->co[$n] = $this->makeCompany("Co {$n}");
            $this->makeAssignment($this->makeEmployee(), $this->co[$n], null, 100, '2026-01-01');
            $this->generate($this->co[$n]);
        }
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

    private function generate(ClientCompany $c): void
    {
        $this->as($this->super)->json('POST', '/api/v1/admin/hr/payroll/'.self::P."/companies/{$c->id}/generate")->assertCreated();
    }

    private function section(string $n): PayrollRunSection
    {
        return PayrollRunSection::query()->where('client_company_id', $this->co[$n]->id)->firstOrFail();
    }

    /** The three "legacy approved" companies (locked, with their approval stamp) and one reviewed one, as production's November holds. */
    private function legacyLikeMonth(): PayrollRun
    {
        foreach (['A', 'B', 'C'] as $n) {
            $this->section($n)->forceFill(['status' => 'approved', 'reviewed_at' => now(), 'approved_at' => now(), 'approved_by' => $this->superId])->save();
        }

        $this->section('D')->forceFill(['status' => 'reviewed', 'reviewed_at' => now()])->save();
        $run = PayrollRun::query()->firstOrFail();
        PayrollSections::syncRun($run);

        return $run->refresh();
    }

    /** @return list<array<mixed>> */
    private function snapshot(): array
    {
        return PayrollRunSection::query()->orderBy('id')->get(['id', 'status', 'reviewed_at', 'approved_at', 'approved_by', 'totals'])->map(fn ($s): array => [$s->id, $s->status, $s->reviewed_at?->toIso8601String(), $s->approved_at?->toIso8601String(), $s->approved_by, $s->totals])->all();
    }

    public function test_the_month_level_reopen_endpoint_is_gone_for_every_role_and_changes_nothing(): void
    {
        $run = $this->legacyLikeMonth();
        $before = $this->snapshot();
        $statusBefore = $run->status;

        foreach ([$this->super, $this->hr] as $token) {
            $this->as($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/reopen", ['reason' => 'Looking at the month again please'])->assertNotFound();
            $this->as($token)->json('PATCH', "/api/v1/admin/hr/payroll-runs/{$run->id}/reopen")->assertNotFound();
            $this->as($token)->postJson("/api/v1/admin/hr/payroll-runs/{$run->id}/reopen", ['reason' => 'Looking at the month again please'])->assertStatus(404);
        }

        $this->assertSame($before, $this->snapshot(), 'not one section was touched (status, stamps, totals)');
        $this->assertSame($statusBefore, $run->fresh()->status);
        $this->assertSame(3, PayrollRunSection::query()->where('status', 'approved')->count());
    }

    public function test_no_route_or_class_can_reopen_a_whole_month(): void
    {
        $reopenRoutes = collect($this->app['router']->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_contains($route->uri(), 'payroll') && str_contains(strtolower($route->uri().$route->getActionName()), 'reopen'))
            ->map(fn ($route): string => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri())
            ->values()->all();

        $this->assertSame(['PATCH api/v1/admin/hr/payroll-run-sections/{payrollRunSection}/reopen'], $reopenRoutes, 'the only payroll reopen route names exactly one company section');
        $this->assertFileDoesNotExist(app_path('Actions/Hr/TransitionPayrollRunAction.php'));
        $this->assertFalse(method_exists(PayrollRunController::class, 'reopen'));
        $this->assertFalse(method_exists(TransitionPayrollRunSectionAction::class, 'bulk'), 'no bulk path that could recreate the month-wide behaviour');
        $this->assertTrue(method_exists(TransitionPayrollRunSectionAction::class, 'reopen'), 'per-company reopen is preserved');
    }

    public function test_per_company_reopen_still_works_with_its_reason_authorization_and_audit_and_touches_only_that_company(): void
    {
        $this->legacyLikeMonth();
        $reviewed = $this->section('D');
        $stamp = fn (string $n): array => [$this->section($n)->status, $this->section($n)->approved_at?->toIso8601String(), $this->section($n)->approved_by];
        $others = collect(['A', 'B', 'C'])->mapWithKeys(fn ($n): array => [$n => $stamp($n)])->all();
        $url = "/api/v1/admin/hr/payroll-run-sections/{$reviewed->id}/reopen";

        // The reason is required and checked (at least 10 characters).
        $this->as($this->hr)->patchJson($url, [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($this->hr)->patchJson($url, ['reason' => 'too short'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('reviewed', $reviewed->fresh()->status);

        // Authorization: a user without payroll approval is refused.
        $this->as($this->tokenWithOnly(['hr.payroll.view']))->patchJson($url, ['reason' => 'Trying without the permission'])->assertForbidden();
        $this->assertSame('reviewed', $reviewed->fresh()->status);

        // The HR Manager reopens ONE reviewed company; every other company is exactly as it was.
        $this->as($this->hr)->patchJson($url, ['reason' => 'A workday was entered wrongly'])->assertOk();
        $this->assertSame('draft', $reviewed->fresh()->status);
        $this->assertNull($reviewed->fresh()->reviewed_at);

        foreach ($others as $n => $was) {
            $this->assertSame($was, $stamp($n), "Co {$n} was not touched by another company's reopen");
        }

        // The audit trail names the company, the previous and new status, the reason and the actor.
        $audit = AuditLog::query()->where('description', 'like', 'Payroll '.self::P.' for Co D reopened to draft.')->latest('id')->firstOrFail();
        $this->assertSame('payroll_section_draft', $audit->metadata['event']);
        $this->assertSame('reviewed', $audit->metadata['previous_status']);
        $this->assertSame('A workday was entered wrongly', $audit->metadata['reason']);
        $this->assertSame($reviewed->client_company_id, $audit->metadata['client_company_id']);
    }

    public function test_a_legacy_approved_company_is_still_reopened_one_at_a_time_and_a_paid_one_only_by_a_super_admin(): void
    {
        $this->legacyLikeMonth();
        $a = $this->section('A');
        $this->as($this->hr)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$a->id}/reopen", ['reason' => 'Reopen this one company only'])->assertOk();
        $this->assertSame(['draft', 'approved', 'approved'], [$a->fresh()->status, $this->section('B')->status, $this->section('C')->status]);

        // A finalized company with a recorded payment: the HR Manager is refused (N-6), the Super Admin may reopen it.
        $this->as($this->hr)->patchJson('/api/v1/admin/hr/payroll-run-sections/'.$this->section('D')->id.'/finalize')->assertOk();
        $this->approvePaid($this->section('D')->id, $this->hr)->assertSuccessful();
        $paid = '/api/v1/admin/hr/payroll-run-sections/'.$this->section('D')->id.'/reopen';
        $this->as($this->hr)->patchJson($paid, ['reason' => 'The salary has to be corrected'])->assertStatus(403)->assertJsonPath('error_code', 'PAID_COMPANY_REOPEN_SUPER_ADMIN_ONLY');
        $this->assertSame('finalized', $this->section('D')->status);
        $this->as($this->super)->patchJson($paid, ['reason' => 'The salary has to be corrected'])->assertOk();
        $this->assertSame('draft', $this->section('D')->status);
    }
}
