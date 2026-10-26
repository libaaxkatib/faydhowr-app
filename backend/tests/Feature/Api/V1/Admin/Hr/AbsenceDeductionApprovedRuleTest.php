<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLeave;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use App\Models\WorkLocation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The approved absence rule (master requirements, sections 17-19):
 *
 *   daily rate = monthly salary / ACTUAL calendar days in that month (weekends and holidays included)
 *   one absent day  -> one daily rate deducted ("Salary $300, 30-day month, 1 absence: deduction $10, payable $290")
 *   absence is recorded and traceable
 *   a covered absent day: the original employee's deduction is ONE daily rate, which is what the
 *   replacement receives ("Original employee absent: deduction $10; replacement receives $10")
 *
 * Absence applies to every assignment (the frozen Phase 5 summary does the same for office staff; only OVERTIME
 * is pending for the office). It is kept separate from permanent-separation final pay (proration up to the last paid
 * day), from approved unpaid leave (its own deduction line) and from Temporary Replacement cost (its own line); a
 * single calendar day is never deducted twice. The legacy summary, the rollup and the stored payroll run must all
 * give the same answer for the same month.
 */
class AbsenceDeductionApprovedRuleTest extends TestCase
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

    /** @return array{0: Employee, 1: EmployeeWorkAssignment} */
    private function clientStaff(int $dollars = 300): array
    {
        $employee = $this->makeEmployee('active', 'permanent');

        return [$employee, $this->makeAssignment($employee, $this->makeCompany(fake()->unique()->company()), null, $dollars, '2026-01-01')];
    }

    private function absent(Employee $employee, string ...$dates): void
    {
        foreach ($dates as $date) {
            EmployeeAttendance::query()->create(['employee_id' => $employee->id, 'date' => $date, 'status' => 'absent']);
        }
    }

    private function summary(string $token, Employee $employee, EmployeeWorkAssignment $assignment, string $period)
    {
        return $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employee->id}/payroll-summary?work_assignment_id={$assignment->id}&period={$period}");
    }

    private function generate(string $token, string $period = '2026-09')
    {
        return $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period]);
    }

    private function approvedCover(EmployeeWorkAssignment $assignment, array $days, bool $approved = true): TemporaryReplacement
    {
        $temp = $this->makeEmployee('active', 'temporary');
        $replacement = TemporaryReplacement::query()->create([
            'work_assignment_id' => $assignment->id, 'replacement_employee_id' => $temp->id,
            'start_date' => '2026-09-01', 'currency' => 'USD', 'status' => 'active', 'approved_at' => $approved ? now() : null,
        ]);

        foreach ($days as $day) {
            $replacement->workdays()->create(['work_assignment_id' => $assignment->id, 'replacement_employee_id' => $temp->id, 'work_date' => sprintf('2026-09-%02d', $day), 'status' => 'verified']);
        }

        return $replacement;
    }

    // ---------------------------------------------------------------- section 18: the approved worked example

    public function test_the_approved_example_salary_300_in_a_30_day_month_one_absence_costs_10_and_pays_290(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->clientStaff(300);
        $this->absent($employee, '2026-09-08');

        $summary = $this->summary($token, $employee, $assignment, '2026-09')->assertOk()->json('data');
        $this->assertSame(1, $summary['absent_days']);
        $this->assertSame('10.00', $summary['daily_rate']);
        $this->assertSame('10.00', $summary['absence_deduction']);
        $this->assertSame('290.00', $summary['net_payable']);

        // The rollup and the stored payroll run say exactly the same.
        $this->withToken($token)->getJson('/api/v1/admin/hr/reports/payroll-rollup?period=2026-09')
            ->assertJsonPath('data.0.net_payable', '290.00')->assertJsonPath('meta.totals.net_payable', '290.00');
        $run = $this->generate($token)->assertCreated();
        $this->assertSame('290.00', $run->json('data.totals.net'));
        $this->assertSame('10.00', $run->json('data.totals.other_deductions'));
    }

    public function test_the_stored_payroll_traces_which_days_were_absent(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee] = $this->clientStaff(300);
        $this->absent($employee, '2026-09-08', '2026-09-09');

        $run = $this->generate($token)->assertCreated()->json('data.id');
        $line = $this->withToken($token)->getJson("/api/v1/admin/hr/payroll-runs/{$run}")->json('data.items.0.deductions.0');

        $this->assertSame('absence', $line['kind']);
        $this->assertSame('20.00', $line['amount']);
        $this->assertSame(['2026-09-08', '2026-09-09'], $line['detail']['absent_dates']);
    }

    public function test_the_divisor_is_the_actual_number_of_days_in_the_month(): void
    {
        [, $token] = $this->superAdminToken();

        foreach ([['2026-01', 310], ['2026-02', 280], ['2026-04', 300], ['2028-02', 290]] as [$period, $dollars]) {
            [$employee, $assignment] = $this->clientStaff($dollars);
            $this->absent($employee, "{$period}-10");

            $this->summary($token, $employee, $assignment, $period)->assertOk()
                ->assertJsonPath('data.absence_deduction', '10.00')
                ->assertJsonPath('data.net_payable', number_format($dollars - 10, 2, '.', ''));
        }
    }

    public function test_weekend_days_are_deducted_like_any_other_day(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->clientStaff(300);
        $this->absent($employee, '2026-09-05', '2026-09-06'); // Saturday and Sunday

        $this->summary($token, $employee, $assignment, '2026-09')->assertOk()->assertJsonPath('data.absent_days', 2)->assertJsonPath('data.net_payable', '280.00');
    }

    public function test_office_staff_use_the_same_daily_rate_formula_and_never_get_an_overtime_figure(): void
    {
        [, $token] = $this->superAdminToken();
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();
        $officer = $this->makeEmployee('active', 'permanent');
        $assignment = $this->makeAssignment($officer, null, $office, 300, '2026-01-01');
        $this->absent($officer, '2026-09-08');

        $data = $this->summary($token, $officer, $assignment, '2026-09')->assertOk()->json('data');
        $this->assertSame('290.00', $data['net_payable']);
        $this->assertSame('office', $data['location_type']);
        $this->assertArrayNotHasKey('overtime', $data);
    }

    // ---------------------------------------------------------------- section 19: a covered day is deducted once

    public function test_an_absent_day_covered_by_an_approved_replacement_is_deducted_once(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->clientStaff(300);
        $this->absent($employee, '2026-09-08');
        $this->approvedCover($assignment, [8]); // the replacement works the day the employee was absent

        $run = $this->generate($token)->assertCreated();

        // "Original employee absent: deduction $10. Replacement receives $10 for that replacement day."
        $this->assertSame('10.00', $run->json('data.totals.replacement_deductions'));
        $this->assertSame('0.00', $run->json('data.totals.other_deductions'), 'the same day must not be deducted a second time as absence');
        $this->assertSame('290.00', $run->json('data.totals.net'));
        $this->summary($token, $employee, $assignment, '2026-09')->assertJsonPath('data.net_payable', '290.00');
    }

    public function test_only_the_covered_days_are_exempt_other_absences_are_still_deducted(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->clientStaff(300);
        $this->absent($employee, '2026-09-08', '2026-09-09');
        $this->approvedCover($assignment, [8]);

        $run = $this->generate($token)->assertCreated();

        $this->assertSame('10.00', $run->json('data.totals.replacement_deductions'));
        $this->assertSame('10.00', $run->json('data.totals.other_deductions')); // 09-09 only
        $this->assertSame('280.00', $run->json('data.totals.net'));
    }

    public function test_an_unapproved_replacement_does_not_change_the_absence_deduction(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->clientStaff(300);
        $this->absent($employee, '2026-09-08');
        $this->approvedCover($assignment, [8], approved: false);

        $run = $this->generate($token)->assertCreated();

        $this->assertSame('0.00', $run->json('data.totals.replacement_deductions'));
        $this->assertSame('10.00', $run->json('data.totals.other_deductions'));
        $this->assertSame('290.00', $run->json('data.totals.net'));
    }

    // ---------------------------------------------------------------- kept separate from leave and final pay

    public function test_an_absent_day_that_is_approved_unpaid_leave_is_deducted_once_as_leave(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee] = $this->clientStaff(300);
        EmployeeLeave::query()->create([
            'employee_id' => $employee->id, 'leave_type' => 'unpaid', 'approval_status' => 'approved',
            'start_date' => '2026-09-20', 'end_date' => '2026-09-21', 'recorded_by' => Admin::factory()->create()->id,
            'decided_by' => Admin::factory()->create()->id, 'decided_at' => now(),
        ]);
        $this->absent($employee, '2026-09-21');

        $run = $this->generate($token)->assertCreated();
        $run = $this->withToken($token)->getJson('/api/v1/admin/hr/payroll-runs/'.$run->json('data.id'))->json('data');
        $kinds = array_column($run['items'][0]['deductions'], 'amount', 'kind');

        $this->assertSame(['unpaid_leave' => '20.00'], $kinds, 'two leave days once; the absence on a leave day adds nothing');
        $this->assertSame('280.00', $run['totals']['net']);
    }

    public function test_absence_after_the_last_paid_day_does_not_reduce_final_pay(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->clientStaff(300);
        $this->absent($employee, '2026-09-08', '2026-09-15');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/separate", [
            'reason' => 'resigned', 'separation_date' => '2026-09-10', 'rehire_eligible' => true,
            'successors' => $this->successorsFor($employee),
        ])->assertOk();

        // Final pay = 10 calendar days = 100.00; only the 09-08 absence falls inside them.
        $data = $this->summary($token, $employee, $assignment->fresh(), '2026-09')->assertOk()->json('data');
        $this->assertSame('100.00', $data['gross_pay']);
        $this->assertSame('10.00', $data['absence_deduction']);
        $this->assertSame('90.00', $data['net_payable']);
        $this->assertSame(1, $data['absent_days']);
    }

    public function test_final_pay_unpaid_leave_replacement_and_absence_are_four_separate_lines_with_no_day_counted_twice(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->clientStaff(300);
        EmployeeLeave::query()->create([
            'employee_id' => $employee->id, 'leave_type' => 'unpaid', 'approval_status' => 'approved',
            'start_date' => '2026-09-02', 'end_date' => '2026-09-02', 'recorded_by' => Admin::factory()->create()->id,
            'decided_by' => Admin::factory()->create()->id, 'decided_at' => now(),
        ]);
        $this->absent($employee, '2026-09-03', '2026-09-04');
        $this->approvedCover($assignment, [4, 5]);
        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/separate", [
            'reason' => 'resigned', 'separation_date' => '2026-09-10', 'rehire_eligible' => true,
            'successors' => $this->successorsFor($employee),
        ])->assertOk();

        $runId = $this->generate($token)->assertCreated()->json('data.id');
        $item = $this->withToken($token)->getJson("/api/v1/admin/hr/payroll-runs/{$runId}")->json('data.items.0');
        $byKind = [];
        foreach ($item['deductions'] as $d) {
            $byKind[$d['kind']] = ($byKind[$d['kind']] ?? 0) + (float) $d['amount'];
        }

        $this->assertSame('100.00', $item['gross']); // final pay: 10 calendar days
        $this->assertEquals(10.0, $byKind['unpaid_leave']); // 09-02
        $this->assertEquals(10.0, $byKind['absence']); // 09-03 only (09-04 is covered)
        $this->assertEquals(20.0, $byKind['replacement']); // 09-04 and 09-05
        $this->assertSame('60.00', $item['net']); // 100 - 10 - 10 - 20
    }

    // ---------------------------------------------------------------- compatibility

    public function test_the_original_summary_keys_are_all_still_returned(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->clientStaff(300);

        $this->summary($token, $employee, $assignment, '2026-09')->assertOk()->assertJsonStructure(['data' => [
            'employee_id', 'work_assignment_id', 'payroll_period', 'location_type', 'monthly_salary', 'currency', 'days_in_month',
            'daily_rate', 'absent_days', 'absence_deduction', 'penalties', 'penalty_deduction', 'advances', 'advance_deduction', 'net_payable',
        ]]);
    }
}
