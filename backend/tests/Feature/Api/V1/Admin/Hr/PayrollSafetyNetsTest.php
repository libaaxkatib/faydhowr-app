<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\EmployeeAdvance;
use App\Models\EmployeePenalty;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\TemporaryReplacement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The payroll run must never silently drop money it cannot calculate, and a
 * month that is approved or finalized must not keep accepting records that
 * would have been deducted from it.
 */
class PayrollSafetyNetsTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-20 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function generate(string $token, string $period = '2026-09')
    {
        return $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period]);
    }

    public function test_penalty_advance_and_attendance_are_refused_in_an_approved_or_finalized_month(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        PayrollRun::query()->create(['period' => '2026-09', 'status' => 'approved']);

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/penalties", [
            'penalty_date' => '2026-09-05', 'reason' => 'Late', 'deduction_amount' => 10, 'currency' => 'USD', 'payroll_period' => '2026-09',
        ])->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_PERIOD_LOCKED');
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/advances", [
            'advance_date' => '2026-09-05', 'amount' => 20, 'currency' => 'USD', 'payroll_period' => '2026-09', 'reason' => 'Rent',
        ])->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_PERIOD_LOCKED');
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/attendance", ['date' => '2026-09-08', 'status' => 'absent'])
            ->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_PERIOD_LOCKED');

        $this->assertSame(0, EmployeePenalty::query()->count() + EmployeeAdvance::query()->count());
        $this->assertDatabaseCount('employee_attendances', 0);
    }

    public function test_other_months_and_draft_months_stay_open(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        PayrollRun::query()->create(['period' => '2026-09', 'status' => 'draft']);

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/penalties", [
            'penalty_date' => '2026-09-05', 'reason' => 'Late', 'deduction_amount' => 10, 'currency' => 'USD', 'payroll_period' => '2026-09',
        ])->assertCreated();
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/attendance", ['date' => '2026-10-02', 'status' => 'present'])->assertOk();
    }

    public function test_a_separated_employee_with_an_open_assignment_is_paid_but_flagged(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee('inactive');
        $this->makeAssignment($employee, $this->makeCompany(), null, 300, '2026-01-01');

        $response = $this->generate($token)->assertCreated();

        $this->assertSame(1, $response->json('data.item_count'));
        $this->assertStringContainsString('still has an open assignment', $response->json('data.warnings.0'));
    }

    public function test_money_that_targets_someone_with_no_payroll_line_is_reported_not_lost(): void
    {
        [, $token] = $this->superAdminToken();
        $unassigned = $this->makeEmployee();
        EmployeePenalty::query()->create(['employee_id' => $unassigned->id, 'penalty_date' => '2026-09-05', 'reason' => 'x', 'deduction_amount' => 10, 'currency' => 'USD', 'payroll_period' => '2026-09']);
        EmployeeAdvance::query()->create(['employee_id' => $unassigned->id, 'advance_date' => '2026-09-05', 'amount' => 20, 'currency' => 'USD', 'payroll_period' => '2026-09', 'reason' => 'x']);

        $warnings = $this->generate($token)->assertCreated()->json('data.warnings');

        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('Penalty', implode(' ', $warnings));
        $this->assertStringContainsString('Advance', implode(' ', $warnings));
    }

    public function test_a_pending_adjustment_whose_assignment_has_no_line_that_month_is_reported(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $this->makeCompany(), null, 300, '2026-01-01');
        $run = $this->generate($token)->json('data.id');
        foreach (['review', 'approve', 'finalize'] as $step) {
            $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/{$step}")->assertOk();
        }
        $itemId = PayrollItem::query()->firstOrFail()->id;
        $this->withToken($token)->postJson("/api/v1/admin/hr/payroll-items/{$itemId}/adjustments", [
            'kind' => 'correction', 'amount' => '5.00', 'reason' => 'Late correction for September', 'applies_in_period' => '2026-10',
        ])->assertCreated();
        $assignment->update(['end_date' => '2026-09-30', 'status' => 'ended']);

        $october = $this->generate($token, '2026-10')->assertCreated();

        $this->assertSame(0, $october->json('data.item_count'));
        $this->assertStringContainsString('cannot apply in 2026-10', $october->json('data.warnings.0'));
        $this->assertSame('pending', PayrollAdjustment::query()->firstOrFail()->status);
    }

    public function test_verified_cover_days_that_cannot_be_deducted_are_reported(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $this->makeCompany(), null, 300, '2026-01-01');
        $assignment->salaryHistories()->update(['frequency' => 'weekly']); // payroll refuses non-monthly pay
        $temp = $this->makeEmployee('active', 'temporary');
        $replacement = TemporaryReplacement::query()->create(['work_assignment_id' => $assignment->id, 'replacement_employee_id' => $temp->id, 'start_date' => '2026-09-01', 'currency' => 'USD', 'status' => 'active', 'approved_at' => now()]);
        $replacement->workdays()->create(['work_assignment_id' => $assignment->id, 'replacement_employee_id' => $temp->id, 'work_date' => '2026-09-03', 'status' => 'verified']);

        $response = $this->generate($token)->assertCreated();

        $this->assertSame(0, $response->json('data.item_count'));
        $this->assertStringContainsString('non-monthly', $response->json('data.warnings.0'));
        $this->assertStringContainsString('1 verified Temporary replacement day(s)', $response->json('data.warnings.1'));
    }
}
