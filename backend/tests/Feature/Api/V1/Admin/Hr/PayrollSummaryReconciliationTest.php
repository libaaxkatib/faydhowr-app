<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLeave;
use App\Models\EmployeePenalty;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The per-employee payroll summary and the payroll rollup have no formula of
 * their own any more: they read the payroll engine's answer. These tests
 * prove that, for the same month, the summary, the rollup and the payroll run
 * can never show contradictory figures, and that anything not backed by an
 * approved/finalized run is labelled an estimate.
 */
class PayrollSummaryReconciliationTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const P = '2026-09';

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

    private function summary(string $token, Employee $employee, EmployeeWorkAssignment $assignment, string $period = self::P)
    {
        return $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employee->id}/payroll-summary?work_assignment_id={$assignment->id}&period={$period}");
    }

    private function rollup(string $token, string $query = '', string $period = self::P)
    {
        return $this->withToken($token)->getJson("/api/v1/admin/hr/reports/payroll-rollup?period={$period}{$query}");
    }

    private function generate(string $token)
    {
        return $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => self::P]);
    }

    private function finalize(string $token, int $run): void
    {
        foreach (['review', 'approve', 'finalize'] as $step) {
            $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/{$step}")->assertOk();
        }
    }

    /** @return array{0: Employee, 1: EmployeeWorkAssignment} */
    private function staff(int $dollars = 300, string $start = '2026-01-01'): array
    {
        $employee = $this->makeEmployee('active', 'permanent');

        return [$employee, $this->makeAssignment($employee, $this->makeCompany(fake()->unique()->company()), null, $dollars, $start)];
    }

    // ------------------------------------------------------------ estimate labelling

    public function test_a_month_without_an_approved_run_is_labelled_an_estimate(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->staff(300);

        $data = $this->summary($token, $employee, $assignment)->assertOk()->json('data');

        $this->assertTrue($data['is_estimate']);
        $this->assertSame('live_estimate', $data['source']);
        $this->assertStringContainsString('Estimate', $data['estimate_note']);
        $this->assertNull($data['run_id']);
        $this->assertSame('300.00', $data['net_payable']);

        $this->rollup($token)->assertOk()->assertJsonPath('meta.is_estimate', true)->assertJsonPath('meta.source', 'live_estimate');
    }

    public function test_a_draft_or_reviewed_run_is_still_an_estimate_for_the_summary(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->staff(300);
        $run = $this->generate($token)->json('data.id');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/review")->assertOk();

        $this->summary($token, $employee, $assignment)->assertOk()
            ->assertJsonPath('data.is_estimate', true)->assertJsonPath('data.run_id', $run)->assertJsonPath('data.run_status', 'reviewed')
            ->assertJsonPath('data.differs_from_run', false);
    }

    public function test_the_estimate_carries_the_warnings_for_that_employee(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->staff(300);
        EmployeeLeave::query()->create([
            'employee_id' => $employee->id, 'leave_type' => 'unpaid', 'approval_status' => 'pending',
            'start_date' => '2026-09-05', 'end_date' => '2026-09-06', 'recorded_by' => Admin::factory()->create()->id,
        ]);

        $data = $this->summary($token, $employee, $assignment)->assertOk()->json('data');

        $this->assertCount(1, $data['warnings']);
        $this->assertStringContainsString('awaiting approval', $data['warnings'][0]);
        $this->assertSame('300.00', $data['net_payable'], 'pending leave is warned about, not deducted');
        $this->rollup($token)->assertJsonPath('meta.warnings.0', $data['warnings'][0]);
    }
    // ------------------------------------------------------------ rules the old formula ignored

    public function test_a_mid_month_start_is_prorated_by_calendar_days_not_shown_as_a_full_month(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->staff(300, '2026-09-11');

        $this->summary($token, $employee, $assignment)->assertOk()
            ->assertJsonPath('data.eligible_days', 20)
            ->assertJsonPath('data.gross_pay', '200.00')
            ->assertJsonPath('data.net_payable', '200.00')
            ->assertJsonPath('data.days_in_month', 30);
    }

    public function test_salary_history_is_used_day_by_day(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->staff(300);
        $assignment->salaryHistories()->create(['effective_from' => '2026-09-16', 'amount' => 600, 'currency' => 'USD', 'frequency' => 'monthly', 'reason' => 'Raise']);
        $assignment->update(['salary_amount' => 600]);

        // 15 days at 300 + 15 days at 600 over 30 days = 450.00 (the old summary showed the current 600.00)
        $data = $this->summary($token, $employee, $assignment)->assertOk()->json('data');
        $this->assertSame('450.00', $data['gross_pay']);
        $this->assertSame('600.00', $data['monthly_salary']);
    }

    public function test_final_pay_after_a_permanent_separation_matches_the_run(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->staff(300);
        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/separate", [
            'reason' => 'resigned', 'separation_date' => '2026-09-10', 'rehire_eligible' => true,
            'successors' => $this->successorsFor($employee),
        ])->assertOk();

        $data = $this->summary($token, $employee, $assignment->fresh())->assertOk()->json('data');
        $this->assertSame('100.00', $data['gross_pay']);
        $this->assertSame('2026-09-10', $data['final_pay']['last_paid_day']);

        $run = $this->generate($token)->assertCreated();
        // The leaver's summary equals the leaver's own line in the run (the replacement chosen at the separation has a line of its own).
        $line = collect($this->withToken($token)->getJson('/api/v1/admin/hr/payroll-runs/'.$run->json('data.id'))->json('data.items'))->firstWhere('employee_id', $employee->id);
        $this->assertSame($data['net_payable'], $line['net']);
    }

    public function test_replacement_cost_and_approved_unpaid_leave_are_in_the_summary(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->staff(300);
        $temp = $this->makeEmployee('active', 'temporary');
        $replacement = TemporaryReplacement::query()->create([
            'work_assignment_id' => $assignment->id, 'replacement_employee_id' => $temp->id,
            'start_date' => '2026-09-01', 'currency' => 'USD', 'status' => 'active', 'approved_at' => now(),
        ]);
        foreach ([3, 4, 5] as $day) {
            $replacement->workdays()->create(['work_assignment_id' => $assignment->id, 'replacement_employee_id' => $temp->id, 'work_date' => sprintf('2026-09-%02d', $day), 'status' => 'verified']);
        }
        EmployeeLeave::query()->create([
            'employee_id' => $employee->id, 'leave_type' => 'unpaid', 'approval_status' => 'approved',
            'start_date' => '2026-09-20', 'end_date' => '2026-09-21', 'recorded_by' => Admin::factory()->create()->id,
            'decided_by' => Admin::factory()->create()->id, 'decided_at' => now(),
        ]);

        $data = $this->summary($token, $employee, $assignment)->assertOk()->json('data');

        $this->assertSame('30.00', $data['replacement_deduction']); // 3 days x 10.00
        $this->assertSame('20.00', $data['unpaid_leave_deduction']); // 2 days x 10.00
        $this->assertSame('250.00', $data['net_payable']);
        $this->assertCount(1, $data['replacements']);
        $this->assertCount(1, $data['unpaid_leaves']);
    }

    public function test_absence_is_deducted_for_every_assignment_per_the_approved_spec(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->staff(300);
        EmployeeAttendance::query()->create(['employee_id' => $employee->id, 'date' => '2026-09-08', 'status' => 'absent']);

        $data = $this->summary($token, $employee, $assignment)->assertOk()->json('data');
        $this->assertSame(1, $data['absent_days']);
        $this->assertSame('10.00', $data['absence_deduction']);
        $this->assertSame('290.00', $data['net_payable']);
    }

    // ------------------------------------------------------------ the same month never shows two answers

    public function test_summary_rollup_and_draft_run_agree_to_the_cent(): void
    {
        [, $token] = $this->superAdminToken();
        [$a, $assignmentA] = $this->staff(300, '2026-09-11');
        [$b, $assignmentB] = $this->staff(450);
        EmployeePenalty::query()->create(['employee_id' => $b->id, 'penalty_date' => '2026-09-05', 'reason' => 'Late', 'deduction_amount' => 15, 'currency' => 'USD', 'payroll_period' => self::P]);

        $estimateA = $this->summary($token, $a, $assignmentA)->json('data.net_payable');
        $estimateB = $this->summary($token, $b, $assignmentB)->json('data.net_payable');
        $estimateRollup = $this->rollup($token)->assertOk();

        $run = $this->generate($token)->assertCreated();

        $this->assertSame('200.00', $estimateA);
        $this->assertSame('435.00', $estimateB);
        $this->assertSame($run->json('data.totals.net'), $estimateRollup->json('meta.totals.net_payable'));
        $this->assertSame($run->json('data.totals.gross'), $estimateRollup->json('meta.totals.gross_pay'));
        $this->assertSame($run->json('data.totals.net'), number_format((float) $estimateA + (float) $estimateB, 2, '.', ''));
        $this->assertFalse($estimateRollup->json('meta.differs_from_run'));
    }

    public function test_changing_data_after_a_draft_is_flagged_instead_of_silently_disagreeing(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->staff(300);
        $this->generate($token)->assertCreated();
        EmployeePenalty::query()->create(['employee_id' => $employee->id, 'penalty_date' => '2026-09-05', 'reason' => 'Late', 'deduction_amount' => 20, 'currency' => 'USD', 'payroll_period' => self::P]);

        $data = $this->summary($token, $employee, $assignment)->assertOk()->json('data');
        $this->assertSame('280.00', $data['net_payable']);
        $this->assertTrue($data['is_estimate']);
        $this->assertTrue($data['differs_from_run'], 'the draft run still says 300.00, so HR must be told it is stale');
        $this->rollup($token)->assertJsonPath('meta.differs_from_run', true);

        $this->generate($token)->assertCreated();
        $this->summary($token, $employee, $assignment)->assertJsonPath('data.differs_from_run', false)->assertJsonPath('data.net_payable', '280.00');
    }

    public function test_after_finalization_the_summary_and_rollup_show_the_stored_figures(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->staff(300);
        $run = $this->generate($token)->assertCreated()->json('data.id');
        $this->finalize($token, $run);

        // Data entered after finalization must not make the screens contradict the locked run.
        EmployeePenalty::query()->create(['employee_id' => $employee->id, 'penalty_date' => '2026-09-25', 'reason' => 'Late entry', 'deduction_amount' => 99, 'currency' => 'USD', 'payroll_period' => self::P]);

        $data = $this->summary($token, $employee, $assignment)->assertOk()->json('data');
        $this->assertFalse($data['is_estimate']);
        $this->assertNull($data['estimate_note']);
        $this->assertSame('stored_run', $data['source']);
        $this->assertSame('finalized', $data['run_status']);
        $this->assertSame('300.00', $data['net_payable']);

        $rollup = $this->rollup($token)->assertOk();
        $rollup->assertJsonPath('meta.is_estimate', false)->assertJsonPath('meta.totals.net_payable', '300.00');
        $this->assertSame($this->withToken($token)->getJson("/api/v1/admin/hr/payroll-runs/{$run}")->json('data.totals.net'), $rollup->json('meta.totals.net_payable'));
    }

    public function test_the_rollup_row_equals_the_individual_summary_field_for_field(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->staff(300, '2026-09-06');
        EmployeePenalty::query()->create(['employee_id' => $employee->id, 'penalty_date' => '2026-09-08', 'reason' => 'Late', 'deduction_amount' => 7, 'currency' => 'USD', 'payroll_period' => self::P]);

        $summary = $this->summary($token, $employee, $assignment)->json('data');
        $row = $this->rollup($token)->json('data.0');

        foreach (['gross_pay', 'eligible_days', 'penalty_deduction', 'replacement_deduction', 'unpaid_leave_deduction', 'absence_deduction', 'net_payable', 'is_estimate', 'source'] as $key) {
            $this->assertSame($summary[$key], $row[$key], "rollup and summary disagree on {$key}");
        }
    }

    // ------------------------------------------------------------ what is not payable

    public function test_a_temporary_employees_assignment_is_not_payable_not_a_made_up_salary(): void
    {
        [, $token] = $this->superAdminToken();
        $temp = $this->makeEmployee('active', 'temporary');
        $assignment = $this->makeAssignment($temp, $this->makeCompany('Temp Co'), null, 300, '2026-01-01');

        $data = $this->summary($token, $temp, $assignment)->assertOk()->json('data');

        $this->assertFalse($data['payable']);
        $this->assertSame('0.00', $data['net_payable']);
        $this->assertStringContainsString('Temporary', $data['not_payable_reason']);
        $this->rollup($token)->assertJsonCount(0, 'data');
    }

    public function test_an_assignment_that_ended_before_the_month_is_not_payable(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->staff(300);
        $assignment->update(['end_date' => '2026-08-31', 'status' => 'ended']);

        $this->summary($token, $employee, $assignment)->assertOk()->assertJsonPath('data.payable', false)->assertJsonPath('data.net_payable', '0.00');
    }

    public function test_a_penalty_is_shown_only_on_the_employees_first_assignment_like_the_run(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee('active', 'permanent');
        $first = $this->makeAssignment($employee, $this->makeCompany('Co A'), null, 300, '2026-01-01');
        $second = $this->makeAssignment($employee, $this->makeCompany('Co B'), null, 100, '2026-01-01');
        EmployeePenalty::query()->create(['employee_id' => $employee->id, 'penalty_date' => '2026-09-05', 'reason' => 'Late', 'deduction_amount' => 15, 'currency' => 'USD', 'payroll_period' => self::P]);

        $this->summary($token, $employee, $first)->assertJsonPath('data.penalty_deduction', '15.00');
        $this->summary($token, $employee, $second)->assertJsonPath('data.penalty_deduction', '0.00');
    }

    // ------------------------------------------------------------ rollup filters and meta

    public function test_rollup_filters_by_company_and_keeps_meta_totals_for_the_filtered_rows(): void
    {
        [, $token] = $this->superAdminToken();
        $companyA = $this->makeCompany('Alpha');
        $companyB = $this->makeCompany('Beta');
        $this->makeAssignment($this->makeEmployee('active', 'permanent'), $companyA, null, 300, '2026-01-01');
        $this->makeAssignment($this->makeEmployee('active', 'permanent'), $companyB, null, 600, '2026-01-01');

        $this->rollup($token, "&client_company_id={$companyA->id}")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.totals.net_payable', '300.00')->assertJsonPath('meta.row_count', 1);
        $this->rollup($token)->assertJsonPath('meta.totals.net_payable', '900.00');
    }

    public function test_a_finalized_run_keeps_a_ended_employees_final_pay_in_the_rollup(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee] = $this->staff(300);
        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/separate", [
            'reason' => 'resigned', 'separation_date' => '2026-09-10', 'rehire_eligible' => true,
            'successors' => $this->successorsFor($employee),
        ])->assertOk();

        // The leaver's final pay stays in the roll-up; the replacement chosen at the separation has a line of its own from 2026-09-11.
        $rows = collect($this->rollup($token)->assertOk()->json('data'));
        $this->assertCount(2, $rows);
        $this->assertSame('100.00', $rows->firstWhere('employee_id', $employee->id)['net_payable']);
    }
}
