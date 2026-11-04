<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Enums\WorkAssignmentStatus;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\CompanyClosure;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\HrPolicy;
use App\Models\PayrollCorrection;
use App\Models\PayrollItem;
use App\Models\TemporaryReplacement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FinalizesCompanies;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decisions 2026-11-04: ending an assignment never needs a replacement and can be undone; a company can be CLOSED (effective date + reason, only its
 * own assignments end, covers are flagged not ended) and REOPENED (only what that closure ended can come back, with confirmation and conflict checks).
 * Nothing is deleted and nothing outside the company changes. Synthetic data only. Alpha Co: a1 300, a2 200 (permanent). Bravo Co: b1 400; the SHARED
 * employee x works for Alpha (100) and Bravo (150).
 */
class CompanyClosureAndAssignmentRecoveryTest extends TestCase
{
    use FinalizesCompanies;
    use HrFixtures;
    use RefreshDatabase;

    private ClientCompany $alpha;

    private ClientCompany $bravo;

    private Employee $e1;

    private Employee $e2;

    private Employee $shared;

    private EmployeeWorkAssignment $a1;

    private EmployeeWorkAssignment $a2;

    private EmployeeWorkAssignment $xAlpha;

    private EmployeeWorkAssignment $xBravo;

    private EmployeeWorkAssignment $b1;

    private string $super;

    private string $hrManager;

    private string $editor;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-05 10:00:00');
        [, $this->super] = $this->superAdminToken();
        $keys = ['hr.view', 'hr.manage', 'hr.payroll.view', 'hr.payroll.manage', 'hr.payroll.approve'];
        $this->hrManager = $this->tokenForRoleWith(AdminRole::HrManager, $keys);
        $this->editor = $this->tokenForRoleWith(AdminRole::HrEmployee, $keys);

        $this->as($this->super)->putJson('/api/v1/admin/hr/policies/'.HrPolicy::MULTI_COMPANY_ASSIGNMENTS, ['enabled' => true, 'reason' => 'The shared employee works two companies'])->assertOk();
        $this->alpha = $this->makeCompany('Alpha Co');
        $this->bravo = $this->makeCompany('Bravo Co');
        $this->e1 = $this->makeEmployee();
        $this->e2 = $this->makeEmployee();
        $this->shared = $this->makeEmployee();
        $this->a1 = $this->makeAssignment($this->e1, $this->alpha, null, 300);
        $this->a2 = $this->makeAssignment($this->e2, $this->alpha, null, 200);
        $this->xAlpha = $this->makeAssignment($this->shared, $this->alpha, null, 100);
        $this->xBravo = $this->makeAssignment($this->shared, $this->bravo, null, 150);
        $this->b1 = $this->makeAssignment($this->makeEmployee(), $this->bravo, null, 400);
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

    private function base(ClientCompany $c): string
    {
        return "/api/v1/admin/hr/client-companies/{$c->id}";
    }

    private function close(string $token, ClientCompany $c, array $over = [])
    {
        return $this->as($token)->postJson($this->base($c).'/close', array_replace(['effective_date' => '2026-10-15', 'reason' => 'The company stopped operating'], $over));
    }

    private function stateOf(EmployeeWorkAssignment $a): array
    {
        $a->refresh();

        return [$a->status->value, $a->end_date?->toDateString()];
    }

    /** @return array<int, array{0: string, 1: ?string}> */
    private function snapshot(EmployeeWorkAssignment ...$assignments): array
    {
        return collect($assignments)->mapWithKeys(fn ($a) => [$a->id => $this->stateOf($a)])->all();
    }

    // ------------------------------------------------------------------------------------------------ End assignment without a replacement

    public function test_ending_a_permanent_assignment_needs_no_replacement_and_changes_nothing_else(): void
    {
        $before = $this->snapshot($this->xBravo, $this->a2, $this->b1);
        $status = $this->e1->status;

        $this->as($this->hrManager)->postJson($this->base($this->alpha)."/employees/{$this->a1->id}/stop", ['end_date' => '2026-10-20', 'reason' => 'Resigned'])->assertOk();

        $this->assertSame(['ended', '2026-10-20'], $this->stateOf($this->a1));
        $this->assertSame($before, $this->snapshot($this->xBravo, $this->a2, $this->b1), 'no other assignment changed');
        $this->assertSame($status, $this->e1->refresh()->status, 'the employee HR status is unchanged');
        $this->assertSame(0, EmployeeWorkAssignment::query()->where('status', 'active')->where('start_date', '>', '2026-10-20')->count(), 'nobody was put in the position');
    }

    public function test_a_wrongly_ended_assignment_can_be_reopened_and_the_reopen_is_audited(): void
    {
        $this->as($this->hrManager)->postJson($this->base($this->alpha)."/employees/{$this->a1->id}/stop", ['end_date' => '2026-10-20', 'reason' => 'Ended by mistake'])->assertOk();

        $this->as($this->hrManager)->postJson($this->base($this->alpha)."/employees/{$this->a1->id}/reopen", ['reason' => 'It was the wrong person'])->assertOk();

        $this->assertSame(['active', null], $this->stateOf($this->a1));
        $event = AuditLog::query()->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $this->a1->id)->get()->firstWhere(fn ($l) => ($l->metadata['event'] ?? '') === 'assignment_reopened');
        $this->assertSame(['hr_manager', '2026-10-20', 'It was the wrong person'], [$event->metadata['reopened_by_role'], $event->metadata['previous_end_date'], $event->metadata['reason']]);
    }

    public function test_an_assignment_that_is_not_ended_or_whose_position_was_refilled_cannot_be_reopened(): void
    {
        $this->as($this->hrManager)->postJson($this->base($this->alpha)."/employees/{$this->a2->id}/reopen", ['reason' => 'It is still active'])->assertStatus(422)->assertJsonPath('error_code', 'ASSIGNMENT_NOT_ENDED');

        $this->as($this->hrManager)->postJson($this->base($this->alpha)."/employees/{$this->a1->id}/stop", ['end_date' => '2026-10-20', 'reason' => 'Resigned'])->assertOk();
        $this->as($this->hrManager)->postJson($this->base($this->alpha)."/employees/{$this->a1->id}/reopen", ['reason' => 'x'])->assertStatus(422);
        $this->assertSame(['ended', '2026-10-20'], $this->stateOf($this->a1));
    }

    // ------------------------------------------------------------------------------------------------ Close company

    public function test_closing_a_company_ends_only_its_assignments_on_the_closing_day_and_keeps_everything_else(): void
    {
        $bravoBefore = $this->snapshot($this->xBravo, $this->b1);
        $sharedStatus = $this->shared->status;

        $this->close($this->hrManager, $this->alpha)->assertCreated()->assertJsonPath('data.summary.assignments_ended', 3)->assertJsonPath('data.closed', true);

        foreach ([$this->a1, $this->a2, $this->xAlpha] as $a) {
            $this->assertSame(['ended', '2026-10-15'], $this->stateOf($a));
        }
        $this->assertSame($bravoBefore, $this->snapshot($this->xBravo, $this->b1), 'the shared employee keeps the assignment at the other company');
        $this->assertSame($sharedStatus, $this->shared->refresh()->status);
        $this->assertSame('inactive', $this->alpha->refresh()->status->value);
        $this->assertSame(3, Employee::query()->whereIn('id', [$this->e1->id, $this->e2->id, $this->shared->id])->count(), 'nobody was deleted');

        $closure = CompanyClosure::query()->sole();
        $this->assertSame(['closed', '2026-10-15', 'hr_manager', 3], [$closure->status, $closure->effective_date->toDateString(), $closure->closed_by_role, $closure->assignments()->count()]);
        $this->assertContains('company_closed', AuditLog::query()->get()->map(fn ($l) => $l->metadata['event'] ?? '')->all());
    }

    public function test_payroll_after_a_mid_month_closure_pays_the_real_days_and_stops_after_the_closing_day(): void
    {
        $this->close($this->hrManager, $this->alpha)->assertCreated();

        $runId = (int) $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-10'])->assertCreated()->json('data.id');
        $alphaNet = PayrollItem::query()->where('payroll_run_id', $runId)->whereIn('work_assignment_id', [$this->a1->id, $this->a2->id, $this->xAlpha->id])->sum('net_cents');
        // October has 31 days; the closing day is the 15th: 15/31 of 300 + 200 + 100 = 290.32.
        $this->assertEqualsWithDelta(29032, $alphaNet, 3);
        $this->assertSame(0, PayrollItem::query()->where('payroll_run_id', $runId)->where('work_assignment_id', $this->a1->id)->where('net_cents', '>', 14516)->count());

        $november = (int) $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-11'])->assertCreated()->json('data.id');
        $this->assertSame(0, PayrollItem::query()->where('payroll_run_id', $november)->whereIn('work_assignment_id', [$this->a1->id, $this->a2->id, $this->xAlpha->id])->count(), 'a closed company has no payroll after the closing day: '.PayrollItem::query()->where('payroll_run_id', $november)->whereIn('work_assignment_id', [$this->a1->id, $this->a2->id, $this->xAlpha->id])->get(['work_assignment_id', 'gross_cents', 'net_cents'])->toJson());
        $this->assertSame(1, PayrollItem::query()->where('payroll_run_id', $november)->where('work_assignment_id', $this->xBravo->id)->count(), 'the other company is paid as before');
    }

    public function test_a_closed_company_takes_no_new_assignment_and_cannot_be_reopened_through_the_edit_form(): void
    {
        $this->close($this->hrManager, $this->alpha)->assertCreated();
        $newcomer = $this->makeEmployee();

        $this->as($this->hrManager)->postJson("/api/v1/admin/hr/employees/{$newcomer->id}/work-assignments", ['client_company_id' => $this->alpha->id, 'assignment_type' => 'permanent', 'start_date' => '2026-11-05', 'salary_amount' => 100, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly'])
            ->assertStatus(422)->assertJsonPath('error_code', 'COMPANY_CLOSED');
        $this->as($this->hrManager)->putJson($this->base($this->alpha), ['status' => 'active'])->assertStatus(422)->assertJsonPath('error_code', 'COMPANY_CLOSED_USE_REOPEN');
        $this->assertSame('inactive', $this->alpha->refresh()->status->value);
    }

    public function test_the_status_field_cannot_deactivate_a_company_that_still_has_active_assignments(): void
    {
        $this->as($this->hrManager)->putJson($this->base($this->alpha), ['status' => 'inactive'])->assertStatus(422)->assertJsonPath('error_code', 'COMPANY_HAS_ACTIVE_ASSIGNMENTS_USE_CLOSE');
        $this->assertSame('active', $this->alpha->refresh()->status->value);
    }

    public function test_a_second_close_and_an_unauthorised_role_are_refused_and_change_nothing(): void
    {
        $this->close($this->editor, $this->alpha)->assertForbidden()->assertJsonPath('error_code', 'COMPANY_CLOSE_NOT_ALLOWED');
        $this->close($this->hrManager, $this->alpha, ['reason' => 'no'])->assertStatus(422);
        $this->assertSame(0, CompanyClosure::query()->count());
        $this->assertSame(['active', null], $this->stateOf($this->a1));

        $this->close($this->hrManager, $this->alpha)->assertCreated();
        $this->close($this->super, $this->alpha)->assertStatus(422)->assertJsonPath('error_code', 'COMPANY_ALREADY_CLOSED');
        $this->assertSame(1, CompanyClosure::query()->count());
    }

    public function test_assignments_that_never_started_are_cancelled_and_ones_that_already_ended_are_left_alone(): void
    {
        $future = $this->makeAssignment($this->makeEmployee(), $this->alpha, null, 100, '2026-12-01');
        $this->as($this->hrManager)->postJson($this->base($this->alpha)."/employees/{$this->a2->id}/stop", ['end_date' => '2026-10-05', 'reason' => 'Left earlier'])->assertOk();

        $this->close($this->hrManager, $this->alpha)->assertCreated()->assertJsonPath('data.summary.assignments_cancelled', 1);

        $this->assertSame(['cancelled', null], $this->stateOf($future));
        $this->assertSame(['ended', '2026-10-05'], $this->stateOf($this->a2), 'an assignment that ended before the closing day keeps its own end date');
        $this->assertSame(0, CompanyClosure::query()->sole()->assignments()->where('work_assignment_id', $this->a2->id)->count());
    }

    public function test_active_temporary_covers_are_flagged_for_review_and_not_ended(): void
    {
        $cover = TemporaryReplacement::query()->create(['work_assignment_id' => $this->a1->id, 'replacement_employee_id' => $this->makeEmployee('active', 'temporary')->id, 'start_date' => '2026-10-10', 'end_date' => null, 'daily_rate' => 10, 'currency' => 'USD', 'status' => 'active', 'created_by' => null]);

        $response = $this->close($this->hrManager, $this->alpha)->assertCreated();

        $this->assertSame([$cover->id], array_column($response->json('data.summary.covers_to_review'), 'cover_id'));
        $cover->refresh();
        $this->assertSame(['active', null], [is_object($cover->status) ? $cover->status->value : $cover->status, $cover->end_date?->toDateString()], 'the cover is untouched');
    }

    // ------------------------------------------------------------------------------------------------ Reopen company

    public function test_reopening_restores_only_the_confirmed_assignments_of_that_closure(): void
    {
        $this->close($this->hrManager, $this->alpha)->assertCreated();
        $preview = $this->as($this->hrManager)->getJson($this->base($this->alpha).'/closure')->assertOk()->json('data');
        $this->assertTrue($preview['closed']);
        $this->assertSame(3, count($preview['assignments']));
        $this->assertSame([true, true, true], array_column($preview['assignments'], 'restorable'));

        $this->as($this->hrManager)->postJson($this->base($this->alpha).'/reopen', ['reason' => 'Closed by mistake', 'restore_assignment_ids' => [$this->b1->id]])->assertStatus(422)->assertJsonPath('error_code', 'RESTORE_NOT_OF_CLOSURE');
        $this->assertSame('inactive', $this->alpha->refresh()->status->value);

        $this->as($this->hrManager)->postJson($this->base($this->alpha).'/reopen', ['reason' => 'Closed by mistake', 'restore_assignment_ids' => [$this->a1->id, $this->xAlpha->id]])->assertOk();

        $this->assertSame('active', $this->alpha->refresh()->status->value);
        $this->assertSame(['active', null], $this->stateOf($this->a1));
        $this->assertSame(['active', null], $this->stateOf($this->xAlpha));
        $this->assertSame(['ended', '2026-10-15'], $this->stateOf($this->a2), 'not confirmed, so it stays ended');
        $this->assertSame(['active', null], $this->stateOf($this->xBravo), 'the other company was never touched');
        $closure = CompanyClosure::query()->sole();
        $this->assertSame('reopened', $closure->status);
        $this->assertSame([$this->a2->id], $closure->reopen_summary['assignments_left_ended']);

        $this->as($this->hrManager)->postJson($this->base($this->alpha).'/reopen', ['reason' => 'Again'])->assertStatus(422)->assertJsonPath('error_code', 'COMPANY_NOT_CLOSED');
        // The company can be closed again later, and the history keeps both closures.
        $this->close($this->hrManager, $this->alpha, ['effective_date' => '2026-11-03'])->assertCreated();
        $this->assertSame(2, CompanyClosure::query()->count());
    }

    public function test_a_conflict_found_at_reopening_is_shown_in_the_preview_and_blocks_the_restore(): void
    {
        $this->close($this->hrManager, $this->alpha)->assertCreated();
        $this->e1->update(['status' => 'inactive']);

        $rows = collect($this->as($this->hrManager)->getJson($this->base($this->alpha).'/closure')->assertOk()->json('data.assignments'))->keyBy('assignment_id');
        $this->assertFalse($rows[$this->a1->id]['restorable']);
        $this->assertStringContainsString('no longer an Active employee', $rows[$this->a1->id]['conflict']);
        $this->assertTrue($rows[$this->a2->id]['restorable']);

        $this->as($this->hrManager)->postJson($this->base($this->alpha).'/reopen', ['reason' => 'Reopening the company', 'restore_assignment_ids' => [$this->a1->id]])->assertStatus(422)->assertJsonPath('error_code', 'RESTORE_CONFLICT');
        $this->assertSame('inactive', $this->alpha->refresh()->status->value, 'a refused restore changes nothing');
        $this->assertSame(['ended', '2026-10-15'], $this->stateOf($this->a1));
    }

    // ------------------------------------------------------------------------------------------------ Closed payroll

    public function test_a_closure_that_reaches_finalized_payroll_is_a_recorded_correction_never_a_silent_rewrite(): void
    {
        CarbonImmutable::setTestNow('2026-10-05 10:00:00');
        $runId = (int) $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])->assertCreated()->json('data.id');
        $this->finalizeEveryCompany($runId, $this->super);
        $itemsBefore = PayrollItem::query()->orderBy('id')->get(['id', 'gross_cents', 'net_cents'])->toArray();

        // Closing on 20 September removes the last 10 days of September, which is finalized: a correction reason is required, and nothing is saved without it.
        $this->close($this->hrManager, $this->alpha, ['effective_date' => '2026-09-20'])->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');
        $this->assertSame(0, CompanyClosure::query()->count());
        $this->assertSame(['active', null], $this->stateOf($this->a1));

        $this->close($this->editor, $this->alpha, ['effective_date' => '2026-09-20', 'correction_reason' => 'Closure reaches September'])->assertForbidden();

        $this->close($this->hrManager, $this->alpha, ['effective_date' => '2026-09-20', 'correction_reason' => 'The company closed on 20 September'])->assertCreated();

        $this->assertSame($itemsBefore, PayrollItem::query()->orderBy('id')->get(['id', 'gross_cents', 'net_cents'])->toArray(), 'the finalized September lines are untouched');
        $correction = PayrollCorrection::query()->where('client_company_id', $this->alpha->id)->orderBy('id')->first();
        $this->assertNotNull($correction);
        $this->assertSame(['overpayment', 'company_closed', '2026-09'], [$correction->kind, $correction->action, $correction->period]);
        $this->assertSame(0, PayrollCorrection::query()->where('client_company_id', $this->bravo->id)->count(), 'the other company needs no correction');
        $this->assertSame('hr_manager', AuditLog::query()->where('entity_type', PayrollCorrection::class)->firstOrFail()->metadata['corrected_by_role']);
        $this->assertSame(WorkAssignmentStatus::Ended, $this->a1->refresh()->status);
    }

    public function test_a_back_dated_separation_that_reaches_finalized_payroll_is_a_recorded_correction_too(): void
    {
        CarbonImmutable::setTestNow('2026-10-05 10:00:00');
        $runId = (int) $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])->assertCreated()->json('data.id');
        $this->finalizeEveryCompany($runId, $this->super);
        $itemsBefore = PayrollItem::query()->orderBy('id')->get(['id', 'gross_cents', 'net_cents'])->toArray();
        $url = "/api/v1/admin/hr/employees/{$this->e1->id}/separate";
        $body = ['reason' => 'resigned', 'separation_date' => '2026-09-20', 'rehire_eligible' => true, 'successors' => $this->successorsFor($this->e1)];

        // Before this change a separation silently moved the end of the assignment inside a finalized month, with no correction record.
        $this->as($this->hrManager)->patchJson($url, $body)->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');
        $this->assertSame('active', $this->e1->refresh()->status->value);
        $this->assertSame(['active', null], $this->stateOf($this->a1));
        $this->as($this->editor)->patchJson($url, [...$body, 'correction_reason' => 'Resigned on 20 September'])->assertForbidden();

        $this->as($this->hrManager)->patchJson($url, [...$body, 'correction_reason' => 'Resigned on 20 September'])->assertOk();

        $this->assertSame($itemsBefore, PayrollItem::query()->orderBy('id')->get(['id', 'gross_cents', 'net_cents'])->toArray(), 'the finalized September lines are untouched');
        $correction = PayrollCorrection::query()->where('employee_id', $this->e1->id)->firstOrFail();
        $this->assertSame(['separated', 'overpayment', '2026-09'], [$correction->action, $correction->kind, $correction->period]);
        $this->assertSame('hr_manager', AuditLog::query()->where('entity_type', PayrollCorrection::class)->firstOrFail()->metadata['corrected_by_role']);
    }
}
