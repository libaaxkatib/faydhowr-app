<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollRun;
use App\Models\TemporaryReplacement;
use App\Models\TemporaryReplacementWorkday;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

class ReplacementWorkdayLedgerTest extends TestCase
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

    /**
     * @return array{0: Employee, 1: EmployeeWorkAssignment, 2: Employee, 3: TemporaryReplacement}
     */
    private function scenario(int $monthlyDollars = 300): array
    {
        $permanent = $this->makeEmployee('active', 'permanent');
        $assignment = $this->makeAssignment($permanent, $this->makeCompany(fake()->unique()->company()), null, $monthlyDollars, '2026-01-01');
        $temp = $this->makeEmployee('active', 'temporary');
        $replacement = $this->makeReplacement($assignment, $temp, '2026-09-01');

        return [$permanent, $assignment, $temp, $replacement];
    }

    private function makeReplacement(EmployeeWorkAssignment $assignment, Employee $temp, string $start, ?string $end = null): TemporaryReplacement
    {
        return TemporaryReplacement::query()->create([
            'work_assignment_id' => $assignment->id, 'replacement_employee_id' => $temp->id,
            'start_date' => $start, 'end_date' => $end, 'currency' => 'USD', 'status' => 'active',
        ]);
    }

    private function dates(int $from, int $to, string $month = '2026-09'): array
    {
        return array_map(fn (int $d): string => sprintf('%s-%02d', $month, $d), range($from, $to));
    }

    private function record(string $token, TemporaryReplacement $r, array $dates, bool $verify = true)
    {
        return $this->withToken($token)->postJson("/api/v1/admin/hr/temporary-replacements/{$r->id}/workdays", ['dates' => $dates, 'verify' => $verify]);
    }

    public function test_the_coverage_period_produces_the_derived_daily_rate_and_amount(): void
    {
        [, $token] = $this->superAdminToken();
        [, , , $replacement] = $this->scenario(300);
        // U8: the cover ran from 3 to 10 September, both inclusive; the verified workdays are attendance records of the same days.
        $replacement->update(['start_date' => '2026-09-03', 'end_date' => '2026-09-10']);

        $this->record($token, $replacement, $this->dates(3, 10))->assertCreated();

        $this->withToken($token)->getJson("/api/v1/admin/hr/temporary-replacements/{$replacement->id}/cost?period=2026-09")
            ->assertOk()
            ->assertJsonPath('data.covered_days', 8)
            ->assertJsonPath('data.verified_days', 8)
            ->assertJsonPath('data.daily_rate', '10.0000')
            ->assertJsonPath('data.amount', '80.00')
            ->assertJsonPath('data.approved', false);
    }

    public function test_recorded_but_unverified_days_are_attendance_records_and_do_not_change_the_cost(): void
    {
        [, $token] = $this->superAdminToken();
        [, , , $replacement] = $this->scenario(300);
        $replacement->update(['start_date' => '2026-09-03', 'end_date' => '2026-09-05']);

        $response = $this->record($token, $replacement, $this->dates(3, 5), false)->assertCreated();
        // U8: the three days of the cover count whether or not attendance was verified.
        $this->withToken($token)->getJson("/api/v1/admin/hr/temporary-replacements/{$replacement->id}/cost?period=2026-09")
            ->assertJsonPath('data.covered_days', 3)->assertJsonPath('data.verified_days', 0)->assertJsonPath('data.amount', '30.00');
        $this->assertSame(['recorded', 'recorded', 'recorded'], array_column($response->json('data'), 'status'));

        $first = $response->json('data.0.id');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$replacement->id}/workdays/{$first}/verify")->assertOk()->assertJsonPath('data.status', 'verified');
        $this->withToken($token)->getJson("/api/v1/admin/hr/temporary-replacements/{$replacement->id}/cost?period=2026-09")
            ->assertJsonPath('data.verified_days', 1)->assertJsonPath('data.covered_days', 3)->assertJsonPath('data.amount', '30.00');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$replacement->id}/workdays/{$first}/verify")
            ->assertStatus(422)->assertJsonPath('error_code', 'WORKDAY_NOT_VERIFIABLE');
    }

    public function test_future_days_and_days_outside_the_replacement_or_assignment_are_refused(): void
    {
        [, $token] = $this->superAdminToken();
        [, $assignment, , $replacement] = $this->scenario();

        $this->record($token, $replacement, ['2026-10-21'])->assertStatus(422)->assertJsonPath('error_code', 'WORKDAY_IN_FUTURE');
        $this->record($token, $replacement, ['2026-08-31'])->assertStatus(422)->assertJsonPath('error_code', 'WORKDAY_OUTSIDE_REPLACEMENT_WINDOW');

        $replacement->update(['end_date' => '2026-09-10']);
        $this->record($token, $replacement, ['2026-09-11'])->assertStatus(422)->assertJsonPath('error_code', 'WORKDAY_OUTSIDE_REPLACEMENT_WINDOW');

        $assignment->update(['end_date' => '2026-09-05']);
        $this->record($token, $replacement, ['2026-09-06'])->assertStatus(422)->assertJsonPath('error_code', 'WORKDAY_OUTSIDE_ASSIGNMENT');
        $this->assertDatabaseCount('temporary_replacement_workdays', 0);
    }

    public function test_one_bad_date_saves_nothing(): void
    {
        [, $token] = $this->superAdminToken();
        [, , , $replacement] = $this->scenario();

        $this->record($token, $replacement, ['2026-09-02', '2026-09-03', '2026-10-25'])->assertStatus(422);

        $this->assertDatabaseCount('temporary_replacement_workdays', 0);
    }

    public function test_a_day_cannot_be_charged_twice_for_the_same_position(): void
    {
        [, $token] = $this->superAdminToken();
        [, $assignment, , $first] = $this->scenario();
        $second = $this->makeReplacement($assignment, $this->makeEmployee('active', 'temporary'), '2026-09-01');

        $this->record($token, $first, ['2026-09-04'])->assertCreated();

        $this->record($token, $second, ['2026-09-04'])->assertStatus(422)->assertJsonPath('error_code', 'WORKDAY_ALREADY_CHARGED_FOR_POSITION');
        $this->record($token, $first, ['2026-09-04'])->assertStatus(422)->assertJsonPath('error_code', 'WORKDAY_ALREADY_CHARGED_FOR_POSITION');
        $this->assertDatabaseCount('temporary_replacement_workdays', 1);
    }

    public function test_sequential_temporaries_can_cover_the_same_position_on_different_days(): void
    {
        [, $token] = $this->superAdminToken();
        [, $assignment, , $first] = $this->scenario(300);
        $first->update(['start_date' => '2026-09-03', 'end_date' => '2026-09-05']);
        $second = $this->makeReplacement($assignment, $this->makeEmployee('active', 'temporary'), '2026-09-11', '2026-09-14');

        $this->record($token, $first, $this->dates(3, 5))->assertCreated();
        $this->record($token, $second, $this->dates(11, 14))->assertCreated();

        $this->withToken($token)->getJson("/api/v1/admin/hr/temporary-replacements/{$first->id}/cost?period=2026-09")->assertJsonPath('data.amount', '30.00');
        $this->withToken($token)->getJson("/api/v1/admin/hr/temporary-replacements/{$second->id}/cost?period=2026-09")->assertJsonPath('data.amount', '40.00');
    }

    public function test_one_temporary_cannot_work_two_positions_on_the_same_day(): void
    {
        [, $token] = $this->superAdminToken();
        [, , $temp, $first] = $this->scenario();
        [, $otherAssignment] = $this->scenario();
        $second = $this->makeReplacement($otherAssignment, $temp, '2026-09-01');

        $this->record($token, $first, ['2026-09-07'])->assertCreated();

        $this->record($token, $second, ['2026-09-07'])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_ALREADY_RECORDED_THAT_DAY');
    }

    public function test_the_temporary_employee_must_be_active_to_record_workdays(): void
    {
        [, $token] = $this->superAdminToken();
        [, , $temp, $replacement] = $this->scenario();
        $temp->update(['status' => 'waiting']);

        $this->record($token, $replacement, ['2026-09-02'])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ACTIVE');
    }

    public function test_voiding_needs_a_reason_keeps_history_and_frees_the_date(): void
    {
        [, $token] = $this->superAdminToken();
        [, , , $replacement] = $this->scenario(300);
        $replacement->update(['start_date' => '2026-09-08', 'end_date' => '2026-09-08']);
        $id = $this->record($token, $replacement, ['2026-09-08'])->assertCreated()->json('data.0.id');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$replacement->id}/workdays/{$id}/void", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$replacement->id}/workdays/{$id}/void", ['reason' => 'Entered on the wrong day'])
            ->assertOk()->assertJsonPath('data.status', 'void')->assertJsonPath('data.void_reason', 'Entered on the wrong day');

        // Voiding the attendance record does not shorten the cover: the day is still inside its coverage period.
        $this->withToken($token)->getJson("/api/v1/admin/hr/temporary-replacements/{$replacement->id}/cost?period=2026-09")->assertJsonPath('data.amount', '10.00')->assertJsonPath('data.verified_days', 0);
        $this->record($token, $replacement, ['2026-09-08'])->assertCreated();
        $this->assertDatabaseCount('temporary_replacement_workdays', 2); // the voided row is kept
        $this->withToken($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$replacement->id}/workdays/{$id}/void", ['reason' => 'Again, for testing'])
            ->assertStatus(422)->assertJsonPath('error_code', 'WORKDAY_ALREADY_VOID');
    }

    public function test_an_approved_or_finalized_payroll_month_is_locked_for_workdays(): void
    {
        [, $token] = $this->superAdminToken();
        [, , , $replacement] = $this->scenario();
        $id = $this->record($token, $replacement, ['2026-09-08'], false)->json('data.0.id');
        PayrollRun::query()->create(['period' => '2026-09', 'status' => 'approved']);

        $this->record($token, $replacement, ['2026-09-09'])->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_PERIOD_LOCKED');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$replacement->id}/workdays/{$id}/verify")->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_PERIOD_LOCKED');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$replacement->id}/workdays/{$id}/void", ['reason' => 'Trying to change a closed month'])
            ->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_PERIOD_LOCKED');
    }

    public function test_the_database_itself_blocks_a_second_live_charge_for_one_day(): void
    {
        [, , $temp, $replacement] = $this->scenario();
        $row = fn (string $status) => TemporaryReplacementWorkday::query()->create([
            'temporary_replacement_id' => $replacement->id, 'work_assignment_id' => $replacement->work_assignment_id,
            'replacement_employee_id' => $temp->id, 'work_date' => '2026-09-12', 'status' => $status,
        ]);

        $row('verified');
        $row('void'); // a voided duplicate is allowed (history)

        $this->expectException(QueryException::class);
        $row('recorded');
    }

    public function test_workdays_are_listed_and_a_workday_of_another_replacement_cannot_be_touched(): void
    {
        [, $token] = $this->superAdminToken();
        [, , , $a] = $this->scenario();
        [, , , $b] = $this->scenario();
        $id = $this->record($token, $a, ['2026-09-02'], false)->json('data.0.id');

        $this->withToken($token)->getJson("/api/v1/admin/hr/temporary-replacements/{$a->id}/workdays")->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$b->id}/workdays/{$id}/verify")->assertNotFound();
    }

    public function test_only_a_payroll_approver_can_approve_a_replacement_and_only_once(): void
    {
        [, $token] = $this->superAdminToken();
        [, , , $replacement] = $this->scenario();

        $this->withToken($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$replacement->id}/approve")->assertOk()->assertJsonPath('data.approved', true);
        $this->withToken($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$replacement->id}/approve")->assertStatus(422)->assertJsonPath('error_code', 'REPLACEMENT_ALREADY_APPROVED');
    }

    public function test_hr_manage_alone_cannot_approve_a_replacement(): void
    {
        [, , , $replacement] = $this->scenario();

        $this->withToken($this->tokenWithOnly(['hr.view', 'hr.manage']))
            ->patchJson("/api/v1/admin/hr/temporary-replacements/{$replacement->id}/approve")->assertForbidden();
        $this->assertNull($replacement->refresh()->approved_at);
    }

    public function test_hr_manage_alone_cannot_see_replacement_costs(): void
    {
        [, , , $replacement] = $this->scenario();

        $this->withToken($this->tokenWithOnly(['hr.view', 'hr.manage']))
            ->getJson("/api/v1/admin/hr/temporary-replacements/{$replacement->id}/cost?period=2026-09")->assertForbidden();
    }

    public function test_a_temporary_must_cover_a_permanent_and_be_classified_temporary(): void
    {
        [, $token] = $this->superAdminToken();
        $permanent = $this->makeEmployee('active', 'permanent');
        $assignment = $this->makeAssignment($permanent, $this->makeCompany(), null, 300, '2026-01-01');
        $notTemp = $this->makeEmployee('active', 'permanent');
        $tempCovered = $this->makeEmployee('active', 'temporary');
        $tempAssignment = $this->makeAssignment($tempCovered, $this->makeCompany('Another Co'), null, 100, '2026-01-01');
        $temp = $this->makeEmployee('active', 'temporary');

        $payload = fn (int $assignmentId, int $employeeId) => ['work_assignment_id' => $assignmentId, 'replacement_employee_id' => $employeeId, 'start_date' => '2026-09-01'];

        $this->withToken($token)->postJson('/api/v1/admin/hr/temporary-replacements', $payload($assignment->id, $notTemp->id))
            ->assertStatus(422)->assertJsonPath('error_code', 'REPLACEMENT_EMPLOYEE_NOT_TEMPORARY');
        $this->withToken($token)->postJson('/api/v1/admin/hr/temporary-replacements', $payload($tempAssignment->id, $temp->id))
            ->assertStatus(422)->assertJsonPath('error_code', 'COVERED_EMPLOYEE_NOT_PERMANENT');
        $this->withToken($token)->postJson('/api/v1/admin/hr/temporary-replacements', $payload($assignment->id, $temp->id))
            ->assertCreated()->assertJsonPath('data.currency', 'USD');
    }
}
