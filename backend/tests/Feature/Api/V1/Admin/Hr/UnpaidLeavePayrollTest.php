<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLeave;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\WorkLocation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Approved rule: approved UNPAID leave reduces pay by calendar days
 * (salary in effect that day / days in the month, rounded once per leave).
 */
class UnpaidLeavePayrollTest extends TestCase
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

    private function leave(Employee $employee, string $from, string $to, string $status = 'approved', string $type = 'unpaid'): EmployeeLeave
    {
        return EmployeeLeave::query()->create([
            'employee_id' => $employee->id, 'leave_type' => $type, 'approval_status' => $status,
            'start_date' => $from, 'end_date' => $to,
            'recorded_by' => Admin::factory()->create()->id,
            'decided_by' => $status === 'approved' ? Admin::factory()->create()->id : null,
            'decided_at' => $status === 'approved' ? now() : null,
        ]);
    }

    private function generate(string $token, string $period = '2026-09')
    {
        return $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period]);
    }

    // ---------------------------------------------------------------- calculation

    public function test_approved_unpaid_leave_reduces_pay_by_calendar_days(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->makeCompany(), null, 300, '2026-01-01');
        $this->leave($employee, '2026-09-10', '2026-09-14'); // 5 days in a 30-day month: 300 x 5 / 30

        $response = $this->generate($token)->assertCreated();

        $this->assertSame('300.00', $response->json('data.totals.gross'));
        $this->assertSame('50.00', $response->json('data.totals.other_deductions'));
        $this->assertSame('250.00', $response->json('data.totals.net'));
        $line = $this->withToken($token)->getJson('/api/v1/admin/hr/payroll-runs/'.$response->json('data.id'))->json('data.items.0.deductions.0');
        $this->assertSame('unpaid_leave', $line['kind']);
        $this->assertSame(5, $line['detail']['days']);
        $this->assertSame('50.00', $line['amount']);
    }

    public function test_only_unpaid_leave_reduces_pay_and_pending_or_rejected_does_not(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->makeCompany(), null, 300, '2026-01-01');
        $this->leave($employee, '2026-09-01', '2026-09-03', 'approved', 'annual');
        $this->leave($employee, '2026-09-04', '2026-09-05', 'approved', 'sick');
        $this->leave($employee, '2026-09-10', '2026-09-12', 'pending');
        $this->leave($employee, '2026-09-20', '2026-09-22', 'rejected');

        $response = $this->generate($token)->assertCreated();

        $this->assertSame('0.00', $response->json('data.totals.other_deductions'));
        $warnings = $response->json('data.warnings');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('awaiting approval', $warnings[0]);
        $snapshot = PayrollItem::query()->firstOrFail()->calc_snapshot;
        $this->assertCount(1, $snapshot['unpaid_leave_pending_not_deducted']);
    }

    public function test_a_leave_spanning_two_months_deducts_each_months_own_days_and_denominator(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->makeCompany(), null, 300, '2026-01-01');
        $this->leave($employee, '2026-09-28', '2026-10-03'); // 3 days in Sept (30), 3 days in Oct (31)

        $this->generate($token, '2026-09')->assertCreated()->assertJsonPath('data.totals.other_deductions', '30.00');
        $this->generate($token, '2026-10')->assertCreated()->assertJsonPath('data.totals.other_deductions', '29.03'); // 300 x 3 / 31
    }

    public function test_only_days_inside_the_assignment_dates_count(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->makeCompany(), null, 300, '2026-09-11');
        $this->leave($employee, '2026-09-08', '2026-09-14'); // only 11-14 are employed days

        $this->generate($token)->assertCreated()
            ->assertJsonPath('data.totals.gross', '200.00')
            ->assertJsonPath('data.totals.other_deductions', '40.00'); // 4 days x 300 / 30
    }

    public function test_leave_entirely_before_employment_deducts_nothing(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->makeCompany(), null, 300, '2026-09-20');
        $this->leave($employee, '2026-09-01', '2026-09-05');

        $this->generate($token)->assertCreated()->assertJsonPath('data.totals.other_deductions', '0.00');
    }

    public function test_a_salary_change_inside_the_leave_uses_each_days_salary(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $this->makeCompany(), null, 300, '2026-01-01');
        $assignment->salaryHistories()->create(['effective_from' => '2026-09-13', 'amount' => 400, 'currency' => 'USD', 'frequency' => 'monthly']);
        $this->leave($employee, '2026-09-11', '2026-09-14'); // 2 days @300 + 2 days @400 = 1400 / 30

        $this->generate($token)->assertCreated()->assertJsonPath('data.totals.other_deductions', '46.67');
    }

    public function test_an_employee_with_two_jobs_loses_each_jobs_daily_pay(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->makeCompany('Co A'), null, 300, '2026-01-01');
        $this->makeAssignment($employee, $this->makeCompany('Co B'), null, 100, '2026-01-01');
        $this->leave($employee, '2026-09-10', '2026-09-14');

        $response = $this->generate($token)->assertCreated();

        $this->assertSame(2, $response->json('data.item_count'));
        $this->assertSame('66.67', $response->json('data.totals.other_deductions')); // 50.00 + 16.67
    }

    public function test_a_day_covered_by_two_leave_records_is_deducted_once(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->makeCompany(), null, 300, '2026-01-01');
        $this->leave($employee, '2026-09-10', '2026-09-12');
        $this->leave($employee, '2026-09-12', '2026-09-14'); // shares 12 Sept (only possible via direct data)

        $this->generate($token)->assertCreated()->assertJsonPath('data.totals.other_deductions', '50.00'); // 5 distinct days
    }

    public function test_a_day_that_is_both_unpaid_leave_and_marked_absent_is_deducted_once(): void
    {
        [, $token] = $this->superAdminToken();
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, null, $office, 300, '2026-01-01');
        $this->leave($employee, '2026-09-10', '2026-09-14');
        EmployeeAttendance::query()->create(['employee_id' => $employee->id, 'date' => '2026-09-12', 'status' => 'absent']); // inside the leave
        EmployeeAttendance::query()->create(['employee_id' => $employee->id, 'date' => '2026-09-20', 'status' => 'absent']); // outside the leave

        $this->generate($token)->assertCreated()->assertJsonPath('data.totals.other_deductions', '60.00'); // leave 50 + one absence 10
    }

    public function test_regenerating_never_double_applies_the_leave(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->makeCompany(), null, 300, '2026-01-01');
        $this->leave($employee, '2026-09-10', '2026-09-14');

        $this->generate($token)->assertCreated();
        $this->generate($token)->assertCreated()->assertJsonPath('data.totals.other_deductions', '50.00');
        $this->assertDatabaseCount('payroll_item_deductions', 1);
    }

    public function test_the_excel_export_itemizes_unpaid_leave(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->makeCompany(), null, 300, '2026-01-01');
        $this->leave($employee, '2026-09-10', '2026-09-14');
        $run = $this->generate($token)->json('data.id');

        $export = $this->withToken($token)->get("/api/v1/admin/hr/payroll-runs/{$run}/export")->assertOk();
        $sheet = IOFactory::load($export->baseResponse->getFile()->getPathname())->getActiveSheet();

        $this->assertStringContainsString('Unpaid leave 50.00', (string) $sheet->getCell('R4')->getValue());
        $this->assertSame('250.00', (string) $sheet->getCell('T4')->getValue());
    }

    // ------------------------------------------------------------------- approval

    public function test_unpaid_leave_is_recorded_pending_and_other_types_are_effective_immediately(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/leaves", ['leave_type' => 'unpaid', 'start_date' => '2026-09-10', 'end_date' => '2026-09-12'])->assertCreated();
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/leaves", ['leave_type' => 'annual', 'start_date' => '2026-09-20', 'end_date' => '2026-09-21'])->assertCreated();

        $this->assertSame(['pending', 'approved'], EmployeeLeave::query()->orderBy('id')->pluck('approval_status')->all());
    }

    public function test_approval_makes_the_leave_count_and_is_recorded_with_who_and_when(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->makeCompany(), null, 300, '2026-01-01');
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/leaves", ['leave_type' => 'unpaid', 'start_date' => '2026-09-10', 'end_date' => '2026-09-14'])->assertCreated();
        $leave = EmployeeLeave::query()->firstOrFail();

        $this->generate($token)->assertCreated()->assertJsonPath('data.totals.other_deductions', '0.00');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/leaves/{$leave->id}/approve", ['note' => 'Agreed with the employee'])
            ->assertOk()->assertJsonPath('data.approval_status', 'approved')->assertJsonPath('data.decision_note', 'Agreed with the employee');

        $this->generate($token)->assertCreated()->assertJsonPath('data.totals.other_deductions', '50.00');
        $this->assertNotNull($leave->refresh()->decided_at);
        $this->assertSame(1, DB::table('audit_logs')->where('entity_type', EmployeeLeave::class)->where('description', 'like', '%approved%')->count());
    }

    public function test_rejecting_needs_a_note_keeps_the_record_and_stops_any_deduction(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->makeCompany(), null, 300, '2026-01-01');
        $leave = $this->leave($employee, '2026-09-10', '2026-09-14', 'approved');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/leaves/{$leave->id}/reject", [])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/leaves/{$leave->id}/reject", ['note' => 'Recorded by mistake'])->assertOk()->assertJsonPath('data.approval_status', 'rejected');

        $this->generate($token)->assertCreated()->assertJsonPath('data.totals.other_deductions', '0.00');
        $this->assertDatabaseHas('employee_leaves', ['id' => $leave->id, 'approval_status' => 'rejected']);
    }

    public function test_the_recorder_cannot_approve_their_own_leave_unless_super_admin(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->tokenWithOnly(['hr.view', 'hr.manage']);

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/leaves", ['leave_type' => 'unpaid', 'start_date' => '2026-09-10', 'end_date' => '2026-09-12'])->assertCreated();
        $leave = EmployeeLeave::query()->firstOrFail();

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/leaves/{$leave->id}/approve")
            ->assertStatus(422)->assertJsonPath('error_code', 'APPROVER_MUST_DIFFER_FROM_RECORDER');
        $this->assertSame('pending', $leave->refresh()->approval_status);
    }

    public function test_hr_view_alone_cannot_decide_leave(): void
    {
        $employee = $this->makeEmployee();
        $leave = $this->leave($employee, '2026-09-10', '2026-09-12', 'pending');

        $this->withToken($this->tokenWithOnly(['hr.view']))->patchJson("/api/v1/admin/hr/employees/{$employee->id}/leaves/{$leave->id}/approve")->assertForbidden();
    }

    public function test_non_unpaid_leave_cannot_be_decided_and_a_leave_of_another_employee_is_not_found(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $annual = $this->leave($employee, '2026-09-10', '2026-09-12', 'approved', 'annual');
        $other = $this->leave($this->makeEmployee(), '2026-09-10', '2026-09-12', 'pending');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/leaves/{$annual->id}/reject", ['note' => 'not applicable'])
            ->assertStatus(422)->assertJsonPath('error_code', 'LEAVE_NOT_UNPAID');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/leaves/{$other->id}/approve")->assertNotFound();
    }

    // --------------------------------------------------------------------- guards

    public function test_overlapping_unpaid_leave_is_refused_at_recording(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $this->leave($employee, '2026-09-10', '2026-09-14', 'pending');

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/leaves", ['leave_type' => 'unpaid', 'start_date' => '2026-09-14', 'end_date' => '2026-09-16'])
            ->assertStatus(422)->assertJsonPath('error_code', 'LEAVE_OVERLAPS_UNPAID_LEAVE');
        // a rejected one does not block a fresh request
        EmployeeLeave::query()->update(['approval_status' => 'rejected']);
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/leaves", ['leave_type' => 'unpaid', 'start_date' => '2026-09-14', 'end_date' => '2026-09-16'])->assertCreated();
    }

    public function test_a_locked_payroll_month_freezes_unpaid_leave(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        PayrollRun::query()->create(['period' => '2026-09', 'status' => 'finalized']);
        $pending = $this->leave($employee, '2026-09-10', '2026-09-12', 'pending');

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/leaves", ['leave_type' => 'unpaid', 'start_date' => '2026-09-20', 'end_date' => '2026-09-21'])
            ->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_PERIOD_LOCKED');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/leaves/{$pending->id}/approve")
            ->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_PERIOD_LOCKED');
        // Paid leave is unaffected by payroll locks.
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/leaves", ['leave_type' => 'annual', 'start_date' => '2026-09-20', 'end_date' => '2026-09-21'])->assertCreated();
    }

    public function test_existing_leave_keeps_its_meaning_after_the_migration_default(): void
    {
        $employee = $this->makeEmployee();
        $leave = EmployeeLeave::query()->create(['employee_id' => $employee->id, 'leave_type' => 'sick', 'start_date' => '2026-09-01', 'end_date' => '2026-09-02']);

        $this->assertSame('approved', $leave->refresh()->approval_status);
    }
}
