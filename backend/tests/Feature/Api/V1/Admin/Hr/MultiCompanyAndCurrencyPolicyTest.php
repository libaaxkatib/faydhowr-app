<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeWorkAssignment;
use App\Models\HrPolicy;
use App\Models\Position;
use App\Support\Hr\Payroll\PayrollPeriodView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decisions D and F: an employee works for one client company at a time unless the Super Admin switches multiple-company
 * assignments on (default off, audited, Super Admin only); payroll refuses any currency other than USD instead of treating it as
 * dollars.
 */
class MultiCompanyAndCurrencyPolicyTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private ClientCompany $a;

    private ClientCompany $b;

    private Position $position;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->makeCompany('Company A');
        $this->b = $this->makeCompany('Company B');
        $this->position = Position::query()->create(['name' => 'Cleaner P']);
        [, $this->admin] = $this->superAdminToken();
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function assignBody(Employee $employee, array $over = []): array
    {
        return array_replace([
            'employee_id' => $employee->id, 'assignment_type' => 'permanent', 'position_id' => $this->position->id,
            'start_date' => '2026-10-05', 'salary_amount' => 200, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ], $over);
    }

    private function switchOn(bool $on = true, string $reason = 'A cleaner works two sites'): void
    {
        $this->as($this->admin)->putJson('/api/v1/admin/hr/policies/'.HrPolicy::MULTI_COMPANY_ASSIGNMENTS, ['enabled' => $on, 'reason' => $reason])->assertOk();
    }

    // ------------------------------------------------------------------ the policy itself

    public function test_the_policy_is_off_by_default_and_visible_to_hr(): void
    {
        $data = $this->as($this->tokenWithOnly(['hr.view']))->getJson('/api/v1/admin/hr/policies')->assertOk()->json('data');

        $this->assertSame(HrPolicy::MULTI_COMPANY_ASSIGNMENTS, $data['policies'][0]['key']);
        $this->assertFalse($data['policies'][0]['enabled']);
        $this->assertSame(0, $data['employees_with_concurrent_assignments']);
        $this->as($this->noPermissionToken())->getJson('/api/v1/admin/hr/policies')->assertForbidden();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/hr/policies')->assertUnauthorized();
    }

    public function test_only_the_super_admin_can_switch_it_and_every_change_is_audited_with_the_reason(): void
    {
        $url = '/api/v1/admin/hr/policies/'.HrPolicy::MULTI_COMPANY_ASSIGNMENTS;
        $hrManager = $this->tokenWithOnly(['hr.view', 'hr.manage', 'hr.reports.view', 'hr.payroll.view', 'hr.payroll.manage', 'hr.payroll.approve']);

        $this->as($hrManager)->putJson($url, ['enabled' => true, 'reason' => 'I would like it'])->assertForbidden();
        $this->as($this->noPermissionToken())->putJson($url, ['enabled' => true, 'reason' => 'I would like it'])->assertForbidden();
        $this->assertFalse(HrPolicy::isEnabled(HrPolicy::MULTI_COMPANY_ASSIGNMENTS));

        $this->as($this->admin)->putJson($url, ['enabled' => true])->assertUnprocessable()->assertJsonValidationErrors(['reason'], 'errors');
        $this->as($this->admin)->putJson($url, ['reason' => 'missing flag'])->assertUnprocessable();
        $this->as($this->admin)->putJson('/api/v1/admin/hr/policies/unknown_policy', ['enabled' => true, 'reason' => 'whatever happens'])->assertNotFound();

        $this->switchOn(true, 'A cleaner works two sites');
        $this->assertTrue(HrPolicy::isEnabled(HrPolicy::MULTI_COMPANY_ASSIGNMENTS));
        $this->as($this->admin)->putJson($url, ['enabled' => true, 'reason' => 'again'])->assertUnprocessable()->assertJsonPath('error_code', 'HR_POLICY_UNCHANGED');
        $this->switchOn(false, 'Back to one company each');

        $logs = AuditLog::query()->where('entity_type', HrPolicy::class)->orderBy('id')->get();
        $this->assertCount(2, $logs, 'only real changes are audited');
        $this->assertSame([false, true, 'A cleaner works two sites'], [$logs[0]->metadata['before'], $logs[0]->metadata['after'], $logs[0]->metadata['reason']]);
        $this->assertSame([true, false], [$logs[1]->metadata['before'], $logs[1]->metadata['after']]);
        $this->assertNotNull($logs[0]->admin_id);
    }

    // ------------------------------------------------------------------ enforcement while OFF

    public function test_while_off_an_employee_cannot_get_an_overlapping_assignment_at_another_company(): void
    {
        $employee = $this->makeEmployee();
        $this->as($this->admin)->postJson("/api/v1/admin/hr/client-companies/{$this->a->id}/employees", $this->assignBody($employee))->assertCreated();

        $this->as($this->admin)->postJson("/api/v1/admin/hr/client-companies/{$this->b->id}/employees", $this->assignBody($employee, ['start_date' => '2026-10-20']))
            ->assertUnprocessable()->assertJsonPath('error_code', 'EMPLOYEE_ALREADY_ASSIGNED_ELSEWHERE');

        // the older assignment endpoint is held to the same rule
        $this->as($this->admin)->postJson("/api/v1/admin/hr/employees/{$employee->id}/work-assignments", [
            'client_company_id' => $this->b->id, 'start_date' => '2026-10-20', 'salary_amount' => 100, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ])->assertUnprocessable()->assertJsonPath('error_code', 'EMPLOYEE_ALREADY_ASSIGNED_ELSEWHERE');

        $this->assertSame(1, EmployeeWorkAssignment::query()->where('employee_id', $employee->id)->count());
    }

    public function test_a_hand_over_the_next_day_is_allowed_but_not_an_overlap_by_one_day(): void
    {
        $employee = $this->makeEmployee();
        $first = $this->makeAssignment($employee, $this->a, null, 200, '2026-10-01');
        $first->update(['end_date' => '2026-10-10', 'status' => 'ended']);

        $this->as($this->admin)->postJson("/api/v1/admin/hr/client-companies/{$this->b->id}/employees", $this->assignBody($employee, ['start_date' => '2026-10-10']))
            ->assertUnprocessable()->assertJsonPath('error_code', 'EMPLOYEE_ALREADY_ASSIGNED_ELSEWHERE');
        $this->as($this->admin)->postJson("/api/v1/admin/hr/client-companies/{$this->b->id}/employees", $this->assignBody($employee, ['start_date' => '2026-10-11']))->assertCreated();
    }

    public function test_the_existing_rules_for_one_company_and_the_office_are_unchanged(): void
    {
        $employee = $this->makeEmployee();
        $siteOne = $this->makeClientLocation($this->a, 'Site 1');
        $siteTwo = $this->makeClientLocation($this->a, 'Site 2');
        $this->as($this->admin)->postJson("/api/v1/admin/hr/employees/{$employee->id}/work-assignments", ['work_location_id' => $siteOne->id, 'start_date' => '2026-10-01', 'salary_amount' => 100, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly'])->assertCreated();
        $this->as($this->admin)->postJson("/api/v1/admin/hr/employees/{$employee->id}/work-assignments", ['work_location_id' => $siteTwo->id, 'start_date' => '2026-10-01', 'salary_amount' => 100, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly'])
            ->assertCreated();
    }

    public function test_editing_dates_into_an_overlap_is_refused_while_off(): void
    {
        $employee = $this->makeEmployee();
        $early = $this->makeAssignment($employee, $this->a, null, 200, '2026-08-01');
        $early->update(['end_date' => '2026-08-31', 'status' => 'ended']);
        $later = $this->makeAssignment($employee, $this->b, null, 200, '2026-09-01');

        $this->as($this->admin)->putJson("/api/v1/admin/hr/client-companies/{$this->b->id}/employees/{$later->id}", ['start_date' => '2026-08-15'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'EMPLOYEE_ALREADY_ASSIGNED_ELSEWHERE');
        $this->assertSame('2026-09-01', $later->fresh()->start_date->toDateString());
    }

    public function test_the_picker_says_why_someone_at_another_company_cannot_be_chosen_and_allows_them_once_on(): void
    {
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->a, null, 200, '2026-01-01');
        $url = "/api/v1/admin/hr/client-companies/{$this->b->id}/employee-candidates?q=".$employee->employee_number;

        $off = $this->as($this->admin)->getJson($url)->json('data.0');
        $this->assertFalse($off['eligible']);
        $this->assertStringContainsString('Company A', $off['ineligible_reason']);
        $this->assertStringContainsString('not enabled', $off['ineligible_reason']);

        $this->switchOn();
        $this->assertTrue($this->as($this->admin)->getJson($url)->json('data.0.eligible'));
    }

    // ------------------------------------------------------------------ behaviour while ON

    public function test_while_on_each_assignment_keeps_its_own_company_dates_salary_and_currency_and_nothing_is_duplicated(): void
    {
        $this->switchOn();
        $employee = $this->makeEmployee();
        $this->as($this->admin)->postJson("/api/v1/admin/hr/client-companies/{$this->a->id}/employees", $this->assignBody($employee, ['start_date' => '2026-01-01', 'salary_amount' => 300]))->assertCreated();
        $this->as($this->admin)->postJson("/api/v1/admin/hr/client-companies/{$this->b->id}/employees", $this->assignBody($employee, ['start_date' => '2026-10-11', 'salary_amount' => 150, 'salary_currency' => 'EUR']))->assertCreated();

        $rowsA = $this->as($this->admin)->getJson("/api/v1/admin/hr/client-companies/{$this->a->id}/employees?period=2026-10")->json('data');
        $rowsB = $this->as($this->admin)->getJson("/api/v1/admin/hr/client-companies/{$this->b->id}/employees?period=2026-10")->json('data');

        $this->assertCount(1, $rowsA['employees']);
        $this->assertCount(1, $rowsB['employees']);
        $this->assertSame(['300.00', 'USD', 31], [$rowsA['employees'][0]['salary']['amount'], $rowsA['employees'][0]['salary']['currency'], $rowsA['employees'][0]['payable_days']]);
        $this->assertSame(['150.00', 'EUR', 21], [$rowsB['employees'][0]['salary']['amount'], $rowsB['employees'][0]['salary']['currency'], $rowsB['employees'][0]['payable_days']]);
        $this->assertSame(['Company B'], array_column($rowsA['employees'][0]['also_assigned_at'], 'company_name'));
        $this->assertSame(['Company A'], array_column($rowsB['employees'][0]['also_assigned_at'], 'company_name'));
        $this->assertSame(['USD' => 30000], $rowsA['summary']['contracted_monthly_salary_cents_by_currency'], "each company's total holds only its own assignment");
        $this->assertSame(['EUR' => 15000], $rowsB['summary']['contracted_monthly_salary_cents_by_currency'], 'currencies are never combined');
    }

    public function test_payroll_lines_stay_separate_per_assignment_and_an_absence_is_charged_once(): void
    {
        $this->switchOn();
        $employee = $this->makeEmployee();
        $first = $this->makeAssignment($employee, $this->a, null, 310, '2026-01-01');
        $second = $this->makeAssignment($employee, $this->b, null, 200, '2026-01-01');
        EmployeeAttendance::query()->create(['employee_id' => $employee->id, 'date' => '2026-10-08', 'status' => 'absent']);

        $view = (new PayrollPeriodView)->forPeriod('2026-10', [$employee->id]);
        $lines = collect($view['lines'])->keyBy('work_assignment_id');

        $this->assertCount(2, $lines);
        $this->assertSame(31000, (int) $lines[$first->id]['gross_cents']);
        $this->assertSame(20000, (int) $lines[$second->id]['gross_cents']);
        $this->assertSame('Company A', $lines[$first->id]['company_name']);
        $this->assertSame('Company B', $lines[$second->id]['company_name']);
        $this->assertSame(1000, (int) $lines[$first->id]['other_deduction_cents'], 'one absent day = one daily rate (310 / 31), charged once, to the first assignment');
        $this->assertSame(0, (int) $lines[$second->id]['other_deduction_cents'], 'the same absent day is not charged a second time');
    }

    public function test_switching_off_again_changes_nothing_already_recorded_but_blocks_new_overlaps(): void
    {
        $this->switchOn();
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->a, null, 200, '2026-01-01');
        $this->makeAssignment($employee, $this->b, null, 200, '2026-01-01');
        $other = $this->makeEmployee();
        $this->makeAssignment($other, $this->a, null, 200, '2026-01-01');

        $this->assertSame(1, $this->as($this->admin)->getJson('/api/v1/admin/hr/policies')->json('data.employees_with_concurrent_assignments'));
        $this->switchOn(false, 'We no longer need it');

        $this->assertSame(2, EmployeeWorkAssignment::query()->where('employee_id', $employee->id)->where('status', 'active')->count(), 'existing assignments are untouched');
        $this->as($this->admin)->postJson("/api/v1/admin/hr/client-companies/{$this->b->id}/employees", $this->assignBody($other))->assertUnprocessable()->assertJsonPath('error_code', 'EMPLOYEE_ALREADY_ASSIGNED_ELSEWHERE');
    }

    // ------------------------------------------------------------------ F: payroll is USD only

    public function test_payroll_does_not_treat_a_non_usd_salary_as_dollars(): void
    {
        $usd = $this->makeAssignment($this->makeEmployee(), $this->a, null, 300, '2026-01-01');
        $eurEmployee = $this->makeEmployee();
        $eur = $this->makeAssignment($eurEmployee, $this->a, null, 250, '2026-01-01');
        $eur->update(['salary_currency' => 'EUR']);
        $eur->salaryHistories()->update(['currency' => 'EUR']);

        $view = (new PayrollPeriodView)->forPeriod('2026-10');

        $this->assertSame([$usd->id], array_column($view['lines'], 'work_assignment_id'), 'only the USD assignment has a payroll line');
        $this->assertTrue(collect($view['warnings'])->contains(fn (string $w): bool => str_contains($w, "#{$eur->id}") && str_contains($w, 'EUR') && str_contains($w, 'USD only')));
        $this->assertSame(30000, $view['totals']['gross_cents'] ?? array_sum(array_column($view['lines'], 'gross_cents')));
    }

    public function test_a_salary_can_still_be_recorded_in_another_currency_but_is_flagged_not_calculated_on_the_company_screen(): void
    {
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->a, null, 300, '2026-01-01');

        $this->as($this->admin)->putJson("/api/v1/admin/hr/client-companies/{$this->a->id}/employees/{$assignment->id}", ['salary_amount' => 280, 'salary_currency' => 'EUR', 'salary_effective_from' => '2026-10-01'])->assertOk();

        $row = $this->as($this->tokenWithOnly(['hr.view', 'hr.payroll.view']))->getJson("/api/v1/admin/hr/client-companies/{$this->a->id}/employees?period=2026-10")->json('data.employees.0');
        $this->assertSame('EUR', $row['salary']['currency']);
        $this->assertNull($row['payroll']['calculated']);
        $this->assertNotNull($row['payroll']['unavailable_reason']);
    }
}
