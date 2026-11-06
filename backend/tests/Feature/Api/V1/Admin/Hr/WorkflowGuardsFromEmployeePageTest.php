<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollCorrection;
use App\Models\Position;
use App\Models\TemporaryReplacement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FinalizesCompanies;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Server rules behind the Employee Detail workflows (owner decisions UG-2 and UG-6, 2026-11-05). Synthetic data only.
 *
 *  - End Assignment refuses a last day earlier than the first day, exactly like Stop, and a correction reason does not bypass it.
 *  - The Super Admin status override cannot make a person Inactive while an assignment is still Active (even one whose end date is past) or while
 *    the person covers a position as a Temporary Replacement; nothing is closed automatically. Separation keeps working.
 *  - Office Staff registration can carry the correction reason when the start date reaches an approved or finalized payroll month.
 */
class WorkflowGuardsFromEmployeePageTest extends TestCase
{
    use FinalizesCompanies;
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

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    // ------------------------------------------------------------------------------------------ End before start

    public function test_end_assignment_refuses_a_last_day_before_the_first_day_and_a_correction_reason_does_not_bypass_it(): void
    {
        [, $token] = $this->superAdminToken();
        $a = $this->makeAssignment($this->makeEmployee(), $this->makeCompany('Alpha Co'), null, 300, '2026-10-10');

        $this->as($token)->patchJson("/api/v1/admin/hr/work-assignments/{$a->id}/end", ['end_date' => '2026-10-09'])
            ->assertStatus(422)->assertJsonPath('error_code', 'END_BEFORE_ASSIGNMENT_START')->assertJsonPath('errors.end_date.0', 'The last day cannot be before the assignment started.');
        $this->as($token)->patchJson("/api/v1/admin/hr/work-assignments/{$a->id}/end", ['end_date' => '2026-10-09', 'correction_reason' => 'Trying to force an earlier date'])
            ->assertStatus(422)->assertJsonPath('error_code', 'END_BEFORE_ASSIGNMENT_START');

        $a->refresh();
        $this->assertSame(['active', null], [$a->status->value, $a->end_date?->toDateString()], 'nothing was written');

        // The first day itself is a valid last day (a one-day assignment).
        $this->as($token)->patchJson("/api/v1/admin/hr/work-assignments/{$a->id}/end", ['end_date' => '2026-10-10'])->assertOk()->assertJsonPath('data.status', 'ended');
    }

    // ------------------------------------------------------------------------------------------ UG-6

    public function test_status_override_to_inactive_is_refused_while_an_assignment_is_still_active_even_with_a_past_end_date(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee('active');
        $other = $this->makeAssignment($employee, $this->makeCompany('Beta Co'), null, 250, '2026-02-01');
        $a = $this->makeAssignment($employee, $this->makeCompany('Alpha Co'), null, 300, '2026-01-01');
        $a->update(['end_date' => '2026-09-30']); // end date in the past, but the status is still Active
        $otherBefore = $other->fresh()->only(['status', 'end_date']);

        $this->as($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/status", ['status' => 'inactive', 'override_reason' => 'Trying the Super Admin override on a person who still has work'])
            ->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_HAS_OPEN_ASSIGNMENTS');

        $this->assertSame('active', $employee->fresh()->status->value, 'the person is unchanged');
        $this->assertSame('active', $a->fresh()->status->value, 'nothing was closed automatically');
        $this->assertSame($otherBefore, $other->fresh()->only(['status', 'end_date']), 'no other assignment was touched');
        $this->assertSame(0, $employee->statusHistories()->count(), 'no status history was written');
    }

    public function test_status_override_to_inactive_is_refused_while_the_person_covers_a_position_as_a_temporary_replacement(): void
    {
        [, $token] = $this->superAdminToken();
        $holder = $this->makeAssignment($this->makeEmployee('active'), $this->makeCompany('Alpha Co'), null, 300, '2026-01-01');
        $temporary = $this->makeEmployee('active', 'temporary');
        $cover = TemporaryReplacement::query()->create(['work_assignment_id' => $holder->id, 'replacement_employee_id' => $temporary->id, 'start_date' => '2026-10-10', 'end_date' => null, 'daily_rate' => 10, 'currency' => 'USD', 'status' => 'active', 'created_by' => null]);

        $this->as($token)->patchJson("/api/v1/admin/hr/employees/{$temporary->id}/status", ['status' => 'inactive', 'override_reason' => 'Trying the Super Admin override on an active cover'])
            ->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_HAS_OPEN_ASSIGNMENTS');

        $this->assertSame('active', $temporary->fresh()->status->value);
        $this->assertSame('active', $cover->fresh()->status->value, 'the cover was not ended automatically');
    }

    public function test_status_override_to_inactive_works_once_nothing_is_open_and_separation_still_closes_assignments_itself(): void
    {
        [, $token] = $this->superAdminToken();
        $clear = $this->makeEmployee('active');
        $this->as($token)->patchJson("/api/v1/admin/hr/employees/{$clear->id}/status", ['status' => 'inactive', 'override_reason' => 'A person with no assignment and no cover'])->assertOk();
        $this->assertSame('inactive', $clear->fresh()->status->value);

        $working = $this->makeEmployee('active');
        $a = $this->makeAssignment($working, $this->makeCompany('Alpha Co'), null, 300, '2026-01-01');
        $this->as($token)->patchJson("/api/v1/admin/hr/employees/{$working->id}/separate", ['reason' => 'resigned', 'separation_date' => '2026-10-15', 'rehire_eligible' => true])->assertOk();
        $this->assertSame('inactive', $working->fresh()->status->value);
        $this->assertSame(['ended', '2026-10-15'], [$a->fresh()->status->value, $a->fresh()->end_date->toDateString()], 'separation closes the assignment itself');
    }

    // ------------------------------------------------------------------------------------------ Office staff

    public function test_office_staff_registration_into_a_finalized_month_asks_for_a_reason_and_records_the_correction(): void
    {
        [, $super] = $this->superAdminToken();
        $hr = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage', 'hr.payroll.view']);
        $position = Position::query()->create(['name' => 'Receptionist']);
        $this->makeAssignment($this->makeEmployee(), $this->makeCompany('Acme Co'), null, 300, '2026-01-01');
        $runId = (int) $this->as($super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])->assertCreated()->json('data.id');
        $this->finalizeEveryCompany($runId, $super);

        $body = [
            'full_name' => 'Amina Office Test', 'phone' => '0615111222', 'position_id' => $position->id, 'monthly_salary' => '300.00', 'start_date' => '2026-09-10',
            'emergency_contact_name' => 'Hodan Emergency', 'emergency_contact_phone' => '0615333444',
            'guarantor_name' => 'Yusuf Guarantor', 'guarantor_phone' => '0615555666',
        ];
        $employees = Employee::query()->count();
        $assignments = EmployeeWorkAssignment::query()->count();

        $this->as($hr)->postJson('/api/v1/admin/hr/office-staff', $body)->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');
        $this->assertSame([$employees, $assignments, 0], [Employee::query()->count(), EmployeeWorkAssignment::query()->count(), PayrollCorrection::query()->count()], 'refused as a whole');

        $this->as($hr)->postJson('/api/v1/admin/hr/office-staff', [...$body, 'correction_reason' => 'Office staff started on 10 September'])->assertCreated();
        $this->assertSame($assignments + 1, EmployeeWorkAssignment::query()->count());
        $this->assertSame('Office staff started on 10 September', PayrollCorrection::query()->sole()->reason);
    }
}
