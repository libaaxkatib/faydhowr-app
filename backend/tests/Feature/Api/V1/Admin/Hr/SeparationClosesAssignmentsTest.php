<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Employee;
use App\Models\EmployeeSeparation;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Approved rule: a formally confirmed separation closes the employee's OPEN
 * assignments on the separation date, and nothing else.
 */
class SeparationClosesAssignmentsTest extends TestCase
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

    private function separate(string $token, int $employeeId, string $date = '2026-09-10', string $reason = 'resigned')
    {
        return $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employeeId}/separate", [
            'reason' => $reason, 'separation_date' => $date, 'rehire_eligible' => true, 'notes' => 'Confirmed by HR',
        ]);
    }

    public function test_an_open_assignment_ends_on_the_separation_date_and_everything_is_kept(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $this->makeCompany(), null, 300, '2026-01-01');

        $this->separate($token, $employee->id)->assertOk();

        $assignment->refresh();
        $this->assertSame('ended', $assignment->status->value);
        $this->assertSame('2026-09-10', $assignment->end_date->toDateString());
        $this->assertStringContainsString('separated on 2026-09-10', $assignment->notes);
        $this->assertSame(1, $assignment->salaryHistories()->count(), 'Salary history is kept.');
        $this->assertSame('inactive', $employee->refresh()->status->value);
        $this->assertSame(1, EmployeeSeparation::query()->where('employee_id', $employee->id)->count());
        $this->assertDatabaseHas('employee_work_assignments', ['id' => $assignment->id]);
    }

    public function test_every_open_assignment_of_that_employee_is_closed_and_each_change_is_audited(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $a = $this->makeAssignment($employee, $this->makeCompany('Co A'), null, 300, '2026-01-01');
        $b = $this->makeAssignment($employee, $this->makeCompany('Co B'), null, 100, '2026-03-01');

        $this->separate($token, $employee->id)->assertOk();

        $this->assertSame(['ended', 'ended'], [$a->refresh()->status->value, $b->refresh()->status->value]);
        $this->assertSame(2, DB::table('audit_logs')->where('entity_type', EmployeeWorkAssignment::class)->where('description', 'like', '%because employee%separated%')->count());
        $log = DB::table('audit_logs')->where('entity_type', Employee::class)->where('description', 'like', '%separated (%')->orderByDesc('id')->first();
        $meta = json_decode($log->metadata, true);
        $this->assertCount(2, $meta['assignments_closed']);
        $this->assertSame('2026-09-10', $meta['separation_date']);
    }

    public function test_other_peoples_and_already_closed_assignments_are_untouched(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->makeCompany('Co A'), null, 300, '2026-01-01');
        $already = $this->makeAssignment($employee, $this->makeCompany('Co B'), null, 100, '2026-01-01');
        $already->update(['status' => 'ended', 'end_date' => '2026-05-31']);
        $cancelled = $this->makeAssignment($employee, $this->makeCompany('Co C'), null, 100, '2026-01-01');
        $cancelled->update(['status' => 'cancelled']);
        $someoneElse = $this->makeAssignment($this->makeEmployee(), $this->makeCompany('Co D'), null, 500, '2026-01-01');

        $this->separate($token, $employee->id)->assertOk();

        $this->assertSame('2026-05-31', $already->refresh()->end_date->toDateString());
        $this->assertSame('cancelled', $cancelled->refresh()->status->value);
        $this->assertSame('active', $someoneElse->refresh()->status->value);
        $this->assertNull($someoneElse->end_date);
    }

    public function test_an_earlier_planned_end_date_is_kept_and_a_later_one_is_clipped(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $early = $this->makeAssignment($employee, $this->makeCompany('Co A'), null, 300, '2026-01-01');
        $early->update(['end_date' => '2026-08-31']);
        $late = $this->makeAssignment($employee, $this->makeCompany('Co B'), null, 300, '2026-01-01');
        $late->update(['end_date' => '2026-12-31']);

        $this->separate($token, $employee->id)->assertOk();

        $this->assertSame('2026-08-31', $early->refresh()->end_date->toDateString());
        $this->assertSame('ended', $early->status->value);
        $this->assertSame('2026-09-10', $late->refresh()->end_date->toDateString());
    }

    public function test_an_assignment_that_had_not_started_is_cancelled_or_ended_on_its_start_date(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $future = $this->makeAssignment($employee, $this->makeCompany('Co A'), null, 300, '2026-09-20');
        $withCover = $this->makeAssignment($employee, $this->makeCompany('Co B'), null, 300, '2026-09-25');
        TemporaryReplacement::query()->create([
            'work_assignment_id' => $withCover->id, 'replacement_employee_id' => $this->makeEmployee('active', 'temporary')->id,
            'start_date' => '2026-09-26', 'currency' => 'USD', 'status' => 'active',
        ]);

        $this->separate($token, $employee->id)->assertOk();

        $this->assertSame('cancelled', $future->refresh()->status->value);
        $this->assertSame('ended', $withCover->refresh()->status->value);
        $this->assertSame('2026-09-25', $withCover->end_date->toDateString(), 'Never ends before it starts.');
    }

    public function test_replacements_covering_the_closed_position_or_done_by_the_employee_end_too(): void
    {
        [, $token] = $this->superAdminToken();
        $permanent = $this->makeEmployee();
        $assignment = $this->makeAssignment($permanent, $this->makeCompany('Co A'), null, 300, '2026-01-01');
        $coveredBy = $this->makeEmployee('active', 'temporary');
        $covering = TemporaryReplacement::query()->create(['work_assignment_id' => $assignment->id, 'replacement_employee_id' => $coveredBy->id, 'start_date' => '2026-09-01', 'currency' => 'USD', 'status' => 'active']);
        $unrelated = TemporaryReplacement::query()->create([
            'work_assignment_id' => $this->makeAssignment($this->makeEmployee(), $this->makeCompany('Co Z'), null, 200, '2026-01-01')->id,
            'replacement_employee_id' => $this->makeEmployee('active', 'temporary')->id, 'start_date' => '2026-09-01', 'currency' => 'USD', 'status' => 'active',
        ]);

        $this->separate($token, $permanent->id)->assertOk();

        $this->assertSame('ended', $covering->refresh()->status->value);
        $this->assertSame('2026-09-10', $covering->end_date->toDateString());
        $this->assertSame('active', $unrelated->refresh()->status->value);

        // And the Temporary employee leaving ends their own cover.
        $other = TemporaryReplacement::query()->create([
            'work_assignment_id' => $this->makeAssignment($this->makeEmployee(), $this->makeCompany('Co Y'), null, 200, '2026-01-01')->id,
            'replacement_employee_id' => $coveredBy->id, 'start_date' => '2026-09-12', 'currency' => 'USD', 'status' => 'active',
        ]);
        $this->separate($token, $coveredBy->id, '2026-09-20')->assertOk();
        $this->assertSame('ended', $other->refresh()->status->value);
        $this->assertSame('2026-09-20', $other->end_date->toDateString());
    }

    public function test_payroll_pays_up_to_the_separation_date_and_nothing_after(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->makeCompany(), null, 300, '2026-01-01');
        $this->separate($token, $employee->id)->assertOk();

        $september = $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])->assertCreated();
        $this->assertSame('100.00', $september->json('data.totals.gross')); // 10 of 30 days
        $this->assertSame([], $september->json('data.warnings'), 'A correctly ended assignment raises no warning.');
        $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-10'])->assertCreated()->assertJsonPath('data.item_count', 0);
    }

    public function test_rehire_does_not_reopen_the_closed_assignment(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $this->makeCompany(), null, 300, '2026-01-01');
        $this->separate($token, $employee->id)->assertOk();

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/rehire", ['note' => 'Back'])->assertOk();

        $this->assertSame('active', $employee->refresh()->status->value);
        $this->assertSame('ended', $assignment->refresh()->status->value);
        $this->assertSame(1, EmployeeSeparation::query()->where('employee_id', $employee->id)->count());
    }

    public function test_the_audit_note_flags_finalized_payroll_after_the_separation(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->makeCompany(), null, 300, '2026-01-01');
        $run = $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])->json('data.id');
        foreach (['review', 'approve', 'finalize'] as $step) {
            $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/{$step}")->assertOk();
        }

        $this->separate($token, $employee->id, '2026-08-15')->assertOk();

        $log = DB::table('audit_logs')->where('entity_type', Employee::class)->where('description', 'like', '%separated (%')->orderByDesc('id')->first();
        $this->assertSame(['2026-09'], json_decode($log->metadata, true)['finalized_payroll_after_separation']);
        $this->assertDatabaseHas('payroll_items', ['period' => '2026-09']); // the finalized month itself is not rewritten
    }

    public function test_an_employee_with_no_assignments_separates_exactly_as_before(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();

        $this->separate($token, $employee->id)->assertOk();

        $this->assertSame('inactive', $employee->refresh()->status->value);
        $this->assertDatabaseCount('employee_work_assignments', 0);
    }
}
