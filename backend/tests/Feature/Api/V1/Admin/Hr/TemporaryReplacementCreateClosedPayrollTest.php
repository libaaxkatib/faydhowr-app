<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollCorrection;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\TemporaryReplacement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FinalizesCompanies;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decision UG-3: a back-dated Temporary cover that reaches an approved or finalized payroll month is a correction of closed payroll - it needs a
 * written reason and a role that may correct closed payroll (Super Admin or HR Manager) - exactly like editing or ending a cover. The closed run is
 * never rewritten. Synthetic data only; "today" is 20 December 2026.
 */
class TemporaryReplacementCreateClosedPayrollTest extends TestCase
{
    use FinalizesCompanies;
    use HrFixtures;
    use RefreshDatabase;

    private int $adminId;

    private string $super;

    private string $hrManager;

    private string $otherManager;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-12-20 10:00:00');
        [$admin, $this->super] = $this->superAdminToken();
        $this->adminId = $admin->id;
        $this->hrManager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage', 'hr.payroll.view']);
        $this->otherManager = $this->tokenWithOnly(['hr.view', 'hr.manage', 'hr.payroll.view']);
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

    /** @return array{0: Employee, 1: EmployeeWorkAssignment, 2: Employee} */
    private function setUpPosition(int $dollars = 600): array
    {
        $permanent = $this->makeEmployee('active', 'permanent');
        $position = $this->makeAssignment($permanent, $this->makeCompany(fake()->unique()->company()), null, $dollars, '2026-01-01');

        return [$permanent, $position, $this->makeEmployee('active', 'temporary')];
    }

    private function close(string $period): PayrollRun
    {
        $id = $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period])->assertCreated()->json('data.id');
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$id}/review")->assertOk();
        $this->finalizeEveryCompany($id, $this->super);

        return PayrollRun::query()->findOrFail($id);
    }

    private function create(string $token, EmployeeWorkAssignment $position, Employee $temp, array $extra = [])
    {
        return $this->as($token)->postJson('/api/v1/admin/hr/temporary-replacements', [
            'work_assignment_id' => $position->id,
            'replacement_employee_id' => $temp->id,
            'start_date' => '2026-09-21',
            'end_date' => '2026-09-30',
            ...$extra,
        ]);
    }

    private function itemsOf(PayrollRun $run): array
    {
        return PayrollItem::query()->where('payroll_run_id', $run->id)->orderBy('id')->get(['id', 'gross_cents', 'net_cents', 'replacement_deduction_cents'])->toArray();
    }

    public function test_a_back_dated_cover_in_a_finalized_month_is_refused_without_a_reason(): void
    {
        [, $position, $temp] = $this->setUpPosition();
        $run = $this->close('2026-09');
        $before = $this->itemsOf($run);

        $this->create($this->super, $position, $temp)->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');

        $this->assertSame(0, TemporaryReplacement::query()->count(), 'nothing is saved when the reason is missing');
        $this->assertSame($before, $this->itemsOf($run));
    }

    private function assertRecordedAsCorrection(bool $asSuperAdmin): void
    {
        [$permanent, $position, $temp] = $this->setUpPosition(600);
        $run = $this->close('2026-09');
        $before = $this->itemsOf($run);

        $response = $this->create($asSuperAdmin ? $this->super : $this->hrManager, $position, $temp, ['correction_reason' => 'Cover was not recorded in September'])->assertCreated();

        $cover = TemporaryReplacement::query()->findOrFail($response->json('data.id'));
        $this->assertSame('2026-09-21', $cover->start_date->toDateString());
        $this->assertSame($before, $this->itemsOf($run->fresh()), 'the finalized run is exactly as it was');
        $this->assertSame('finalized', $run->fresh()->status);

        // The reason, the closed month and the person are on the cover's audit trail. A new cover is not approved, so nothing is deducted from the Permanent
        // yet and no ledger line moves (that happens only when the cover is approved); the closed run and its items are untouched.
        $audit = AuditLog::query()->where('entity_type', TemporaryReplacement::class)->where('entity_id', $cover->id)->get()->firstWhere('metadata.event', 'temporary_replacement_created_in_closed_payroll');
        $this->assertNotNull($audit);
        $this->assertSame('Cover was not recorded in September', $audit->metadata['reason']);
        $this->assertSame(['2026-09'], $audit->metadata['locked_periods_changed']);
        $this->assertSame(0, PayrollCorrection::query()->where('employee_id', $permanent->id)->count());
    }

    public function test_a_super_admin_with_a_reason_records_the_cover_and_a_payroll_correction_and_the_closed_run_is_not_rewritten(): void
    {
        $this->assertRecordedAsCorrection(true);
    }

    public function test_an_hr_manager_with_a_reason_records_the_cover_and_a_payroll_correction_and_the_closed_run_is_not_rewritten(): void
    {
        $this->assertRecordedAsCorrection(false);
    }

    public function test_the_correction_is_never_applied_when_the_reason_is_too_short_or_the_role_may_not_correct(): void
    {
        [, $position, $temp] = $this->setUpPosition();
        $this->close('2026-09');

        $this->create($this->super, $position, $temp, ['correction_reason' => 'Too short'])->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');
        $this->create($this->otherManager, $position, $temp, ['correction_reason' => 'Cover was not recorded in September'])->assertStatus(403)->assertJsonPath('error_code', 'CLOSED_PAYROLL_CORRECTION_NOT_ALLOWED');

        $this->assertSame(0, TemporaryReplacement::query()->count());
        $this->assertSame(0, PayrollCorrection::query()->count());
    }

    public function test_a_cover_that_touches_only_open_months_needs_no_reason_and_records_no_correction(): void
    {
        [, $position, $temp] = $this->setUpPosition();
        $this->close('2026-09');

        $this->create($this->hrManager, $position, $temp, ['start_date' => '2026-12-01', 'end_date' => '2026-12-10'])->assertCreated();

        $this->assertSame(0, PayrollCorrection::query()->count());
    }

    public function test_the_create_preview_reports_the_closed_months_the_cost_and_the_role_without_saving(): void
    {
        [, $position, $temp] = $this->setUpPosition(600);
        $this->close('2026-09');

        $query = http_build_query(['work_assignment_id' => $position->id, 'replacement_employee_id' => $temp->id, 'start_date' => '2026-09-21', 'end_date' => '2026-09-30']);
        $this->as($this->hrManager)->getJson("/api/v1/admin/hr/temporary-replacements/create-preview?{$query}")->assertOk()
            ->assertJsonPath('data.changes_locked_period', true)
            ->assertJsonPath('data.requires_reason_for_closed_payroll', true)
            ->assertJsonPath('data.requires_super_admin', false)
            ->assertJsonPath('data.locked_periods_changed.0', '2026-09')
            ->assertJsonPath('data.days_after', 10)
            ->assertJsonPath('data.cost_after_cents', 20000);

        $this->as($this->otherManager)->getJson("/api/v1/admin/hr/temporary-replacements/create-preview?{$query}")->assertOk()
            ->assertJsonPath('data.requires_super_admin', true);

        $this->assertSame(0, TemporaryReplacement::query()->count());
    }

    /** B-4: the End Coverage preview asks the same question as the save does. */
    public function test_the_end_coverage_preview_checks_for_a_conflict_only_when_the_last_day_is_later_than_the_recorded_end_like_the_save(): void
    {
        [, $position, $temp] = $this->setUpPosition();
        $cover = TemporaryReplacement::query()->create(['work_assignment_id' => $position->id, 'replacement_employee_id' => $temp->id, 'start_date' => '2026-12-01', 'end_date' => '2026-12-12', 'currency' => 'USD', 'status' => 'active', 'created_by' => $this->adminId]);
        // A second cover of the same position that overlaps it (data saved before the overlap rule): 10-20 December.
        TemporaryReplacement::query()->create(['work_assignment_id' => $position->id, 'replacement_employee_id' => $this->makeEmployee('active', 'temporary')->id, 'start_date' => '2026-12-10', 'end_date' => '2026-12-20', 'currency' => 'USD', 'status' => 'active', 'created_by' => $this->adminId]);

        $base = "/api/v1/admin/hr/temporary-replacements/{$cover->id}/change-preview";

        // Ending on 11 December still overlaps (10-11) but the save allows it: the end preview must not block, the plain correction preview still reports it.
        $this->as($this->super)->getJson("{$base}?end_date=2026-12-11&for=end")->assertOk()->assertJsonPath('data.conflict', null);
        $this->as($this->super)->getJson("{$base}?end_date=2026-12-11")->assertOk()->assertJsonPath('data.conflict.code', 'TEMPORARY_COVER_OVERLAPS_POSITION');
        // A LATER last day is a lengthening: the save refuses it, so the preview reports it too.
        $this->as($this->super)->getJson("{$base}?end_date=2026-12-15&for=end")->assertOk()->assertJsonPath('data.conflict.code', 'TEMPORARY_COVER_OVERLAPS_POSITION');

        $this->as($this->super)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/end", ['end_date' => '2026-12-11'])->assertOk();
        $this->assertSame('2026-12-11', $cover->fresh()->end_date->toDateString());
    }

    public function test_the_daily_rate_is_the_monthly_salary_divided_by_thirty_in_the_create_preview(): void
    {
        [, $position, $temp] = $this->setUpPosition(310);

        $query = http_build_query(['work_assignment_id' => $position->id, 'replacement_employee_id' => $temp->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-03']);
        $this->as($this->super)->getJson("/api/v1/admin/hr/temporary-replacements/create-preview?{$query}")->assertOk()->assertJsonPath('data.cost_after_cents', 3100);
    }
}
