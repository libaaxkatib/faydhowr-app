<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\AssignmentSalaryHistory;
use App\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

class AssignmentSalaryHistoryTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private function changeAssignment(string $token, int $assignmentId, array $payload)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->putJson("/api/v1/admin/hr/work-assignments/{$assignmentId}", $payload);
    }

    public function test_creating_an_assignment_records_the_initial_salary_in_history(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();
        $employee = $this->makeEmployee();

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/work-assignments", [
            'client_company_id' => $company->id,
            'start_date' => '2026-10-01',
            'salary_amount' => 130,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
        ])->assertCreated();

        $this->assertDatabaseHas('assignment_salary_histories', ['effective_from' => '2026-10-01 00:00:00', 'amount' => '130.00', 'currency' => 'USD']);
        $this->assertDatabaseCount('assignment_salary_histories', 1);
    }

    public function test_a_salary_change_keeps_the_old_salary_and_applies_from_its_effective_date(): void
    {
        [, $token] = $this->superAdminToken();
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->makeCompany(), null, 300, '2026-01-01');

        $this->changeAssignment($token, $assignment->id, [
            'salary_amount' => 400, 'salary_effective_from' => '2026-03-01', 'salary_change_reason' => 'Annual increase',
        ])->assertOk();

        $periods = $assignment->refresh()->monthlySalaryPeriods();
        $this->assertSame([
            ['from' => '2026-01-01', 'to' => '2026-02-28', 'monthly_cents' => 30000],
            ['from' => '2026-03-01', 'to' => null, 'monthly_cents' => 40000],
        ], $periods);
        $this->assertSame('400.00', (string) $assignment->salary_amount, 'The current columns follow the salary in effect today.');
    }

    public function test_a_future_dated_change_does_not_change_todays_salary(): void
    {
        [, $token] = $this->superAdminToken();
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->makeCompany(), null, 300, '2026-01-01');
        $future = CarbonImmutable::now()->addMonths(2)->startOfMonth()->toDateString();

        $this->changeAssignment($token, $assignment->id, ['salary_amount' => 500, 'salary_effective_from' => $future])->assertOk();

        $this->assertSame('300.00', (string) $assignment->refresh()->salary_amount);
        $this->assertSame(2, $assignment->salaryHistories()->count());
    }

    public function test_re_entering_the_same_effective_date_corrects_that_row_and_logs_the_old_value(): void
    {
        [, $token] = $this->superAdminToken();
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->makeCompany(), null, 300, '2026-01-01');

        $this->changeAssignment($token, $assignment->id, ['salary_amount' => 350, 'salary_effective_from' => '2026-01-01'])->assertOk();

        $this->assertSame(1, $assignment->salaryHistories()->count());
        $this->assertSame('350.00', (string) $assignment->salaryHistories()->first()->amount);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => $assignment::class, 'entity_id' => $assignment->id]);
    }

    public function test_a_salary_cannot_take_effect_before_the_assignment_starts(): void
    {
        [, $token] = $this->superAdminToken();
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->makeCompany(), null, 300, '2026-06-01');

        $this->changeAssignment($token, $assignment->id, ['salary_amount' => 400, 'salary_effective_from' => '2026-05-31'])
            ->assertStatus(422)->assertJsonPath('error_code', 'SALARY_BEFORE_ASSIGNMENT_START');
    }

    public function test_an_unchanged_salary_creates_no_history_row(): void
    {
        [, $token] = $this->superAdminToken();
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->makeCompany(), null, 300, '2026-01-01');

        $this->changeAssignment($token, $assignment->id, ['salary_amount' => 300, 'notes' => 'just a note'])->assertOk();

        $this->assertSame(1, $assignment->salaryHistories()->count());
    }

    public function test_salary_changes_that_alter_an_approved_or_finalized_month_need_a_super_admin_and_a_reason(): void
    {
        [, $token] = $this->superAdminToken();
        $hrManager = $this->tokenWithOnly(['hr.view', 'hr.manage']);
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->makeCompany(), null, 300, '2026-01-01');
        PayrollRun::query()->create(['period' => '2026-04', 'status' => 'finalized']);

        // Nobody but a Super Admin may change what a closed month paid - nothing is saved.
        $this->changeAssignment($hrManager, $assignment->id, ['salary_amount' => 400, 'salary_effective_from' => '2026-04-15'])
            ->assertStatus(403)->assertJsonPath('error_code', 'PAYROLL_CORRECTION_SUPER_ADMIN_ONLY');
        // A Super Admin must say why.
        $this->changeAssignment($token, $assignment->id, ['salary_amount' => 400, 'salary_effective_from' => '2026-02-01'])
            ->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');
        $this->assertSame(1, AssignmentSalaryHistory::query()->where('work_assignment_id', $assignment->id)->count());

        // A change that leaves every closed month as it was is open to the normal HR editors.
        $this->changeAssignment($hrManager, $assignment->id, ['salary_amount' => 400, 'salary_effective_from' => '2026-05-01'])->assertOk();
        $this->assertSame(2, AssignmentSalaryHistory::query()->where('work_assignment_id', $assignment->id)->count());
    }

    public function test_a_draft_payroll_month_does_not_lock_salary_changes(): void
    {
        [, $token] = $this->superAdminToken();
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->makeCompany(), null, 300, '2026-01-01');
        PayrollRun::query()->create(['period' => '2026-04', 'status' => 'draft']);

        $this->changeAssignment($token, $assignment->id, ['salary_amount' => 400, 'salary_effective_from' => '2026-04-01'])->assertOk();
    }

    public function test_payroll_periods_refuse_a_non_monthly_salary_instead_of_guessing(): void
    {
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->makeCompany(), null, 300, '2026-01-01');
        $assignment->salaryHistories()->update(['frequency' => 'weekly']);

        $this->expectException(\RuntimeException::class);

        $assignment->refresh()->monthlySalaryPeriods();
    }
}
