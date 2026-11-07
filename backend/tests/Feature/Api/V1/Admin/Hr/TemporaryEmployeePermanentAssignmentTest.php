<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Models\TemporaryReplacement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decision B-Q2 (2026-11-06): a Temporary employee may receive a Permanent assignment. Assignment type and employee type stay separate concepts:
 * the employee stays Temporary, the existing Temporary payroll treatment stays in force (no Permanent monthly line is created for the assignment, the
 * Temporary is paid the cover days at Permanent monthly salary / 30), and any conversion to Permanent is a separate, explicit HR action. Synthetic data only.
 */
class TemporaryEmployeePermanentAssignmentTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-05 10:00:00');
        [, $this->token] = $this->superAdminToken();
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

    private function payload(array $overrides = []): array
    {
        return array_replace(['assignment_type' => 'permanent', 'start_date' => '2026-10-15', 'salary_amount' => 250, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly'], $overrides);
    }

    private function generate(string $period): int
    {
        return (int) $this->as($this->token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period])->assertCreated()->json('data.id');
    }

    public function test_a_temporary_employee_receives_a_permanent_assignment_and_stays_temporary(): void
    {
        $temp = $this->makeEmployee('active', 'temporary');
        $company = $this->makeCompany('Alpha Co');
        $employeeBefore = $temp->fresh()->getAttributes();
        $historyBefore = EmployeeStatusHistory::query()->where('employee_id', $temp->id)->count();

        $assignmentId = $this->as($this->token)->postJson("/api/v1/admin/hr/employees/{$temp->id}/work-assignments", $this->payload(['client_company_id' => $company->id]))
            ->assertCreated()->assertJsonPath('data.assignment_type', 'permanent')->json('data.id');

        // Assignment type and employee type are separate: nothing about the employee changed.
        $this->assertSame('temporary', $temp->fresh()->employment_type);
        $this->assertSame($employeeBefore, $temp->fresh()->getAttributes(), 'the employee record is exactly as it was');
        $this->assertSame($historyBefore, EmployeeStatusHistory::query()->where('employee_id', $temp->id)->count());
        $this->assertSame('permanent', EmployeeWorkAssignment::query()->findOrFail($assignmentId)->assignment_type->value);

        // The audit trail records the assignment and says plainly that the type was not changed and no Permanent payroll line follows.
        $log = AuditLog::query()->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $assignmentId)->where('action', 'create')->firstOrFail();
        $this->assertSame(['employment_type' => 'temporary', 'employment_type_changed' => false, 'permanent_payroll_line' => false], $log->metadata['temporary_employee_permanent_assignment']);
        $this->assertSame(0, AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $temp->id)->where('metadata->employment_type->to', 'permanent')->count(), 'no employee-type change was recorded');
    }

    public function test_the_company_page_assignment_path_behaves_the_same(): void
    {
        $temp = $this->makeEmployee('active', 'temporary');
        $company = $this->makeCompany('Beta Co');

        $position = Position::query()->create(['name' => 'Supervisor']);

        $this->as($this->token)->postJson("/api/v1/admin/hr/client-companies/{$company->id}/employees", $this->payload(['employee_id' => $temp->id, 'position_id' => $position->id, 'confirm_additional_company' => false]))
            ->assertCreated()->assertJsonPath('data.assignment_type', 'permanent');

        $this->assertSame('temporary', $temp->fresh()->employment_type);
        $this->assertSame(1, EmployeeWorkAssignment::query()->where('employee_id', $temp->id)->count());
    }

    public function test_payroll_creates_no_permanent_line_for_it_and_the_temporary_is_still_paid_the_cover_days_at_salary_over_30(): void
    {
        // A Permanent covered for 10 days (1-10 October 2026) by the Temporary: $300 / 30 = $10 a day, so $100.00.
        $permanent = $this->makeEmployee('active', 'permanent');
        $coveredAssignment = $this->makeAssignment($permanent, $this->makeCompany('Cover Co'), null, 300, '2026-01-01');
        $temp = $this->makeEmployee('active', 'temporary');
        $cover = TemporaryReplacement::query()->create([
            'work_assignment_id' => $coveredAssignment->id, 'replacement_employee_id' => $temp->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-10',
            'currency' => 'USD', 'status' => 'ended', 'approved_at' => now(),
        ]);

        // September exists before the Permanent assignment is given; it must not change.
        $sept = $this->generate('2026-09');
        $septBefore = PayrollItem::query()->where('payroll_run_id', $sept)->orderBy('id')->get()->map->getAttributes()->all();

        $newCompany = $this->makeCompany('Gamma Co');
        $assignmentId = $this->as($this->token)->postJson("/api/v1/admin/hr/employees/{$temp->id}/work-assignments", $this->payload(['client_company_id' => $newCompany->id]))->assertCreated()->json('data.id');

        // The expected payment of the cover is unchanged: the fixed divisor of 30 and the eligible cover days.
        $this->as($this->token)->getJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/expected-payment")->assertOk()
            ->assertJsonPath('data.expected', '100.00')->assertJsonPath('data.months.0.rate_divisor', 30);

        // Control: the very same assignment given to a PERMANENT employee does create a monthly line, so the absence below is the rule at work, not a quirk of the fixture.
        $control = $this->makeEmployee('active', 'permanent');
        $this->as($this->token)->postJson("/api/v1/admin/hr/employees/{$control->id}/work-assignments", $this->payload(['client_company_id' => $this->makeCompany('Control Co')->id]))->assertCreated();

        $oct = $this->generate('2026-10');
        $this->assertSame(1, PayrollItem::query()->where('payroll_run_id', $oct)->where('employee_id', $control->id)->count(), 'a Permanent employee with that assignment is paid');

        // No payroll line exists for the Temporary's Permanent assignment (nor for the Temporary at all), and the company it was given at has no line.
        $this->assertSame(0, PayrollItem::query()->where('payroll_run_id', $oct)->where('work_assignment_id', $assignmentId)->count());
        $this->assertSame(0, PayrollItem::query()->where('payroll_run_id', $oct)->where('employee_id', $temp->id)->count());

        // The covered Permanent is still charged the cover cost, $10 x 10 days, exactly as before.
        $permanentItem = PayrollItem::query()->where('payroll_run_id', $oct)->where('employee_id', $permanent->id)->firstOrFail();
        $this->assertSame(10000, (int) $permanentItem->replacement_deduction_cents);

        // The run says why there is no line for it (the existing Temporary warning), instead of silently skipping it.
        $warnings = implode(' | ', (array) (PayrollRun::query()->findOrFail($oct)->totals['warnings'] ?? []));
        $this->assertStringContainsString("Assignment #{$assignmentId} belongs to a Temporary employee", $warnings);

        // Earlier payroll is not overwritten.
        $this->assertSame($septBefore, PayrollItem::query()->where('payroll_run_id', $sept)->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame('temporary', $temp->fresh()->employment_type);
    }

    public function test_invalid_and_unauthorized_assignment_attempts_are_still_refused_and_change_nothing(): void
    {
        $temp = $this->makeEmployee('active', 'temporary');
        $waiting = $this->makeEmployee('waiting', 'temporary');
        $company = $this->makeCompany('Delta Co');
        $assignments = EmployeeWorkAssignment::query()->count();
        $audit = AuditLog::query()->count();

        // Someone without the HR permission.
        $this->as($this->noPermissionToken())->postJson("/api/v1/admin/hr/employees/{$temp->id}/work-assignments", $this->payload(['client_company_id' => $company->id]))->assertStatus(403);
        // A Temporary who is not Active yet.
        $this->as($this->token)->postJson("/api/v1/admin/hr/employees/{$waiting->id}/work-assignments", $this->payload(['client_company_id' => $company->id]))->assertStatus(422);
        // An unknown assignment type, a missing salary, a company that does not exist.
        $this->as($this->token)->postJson("/api/v1/admin/hr/employees/{$temp->id}/work-assignments", $this->payload(['client_company_id' => $company->id, 'assignment_type' => 'forever']))->assertStatus(422);
        $this->as($this->token)->postJson("/api/v1/admin/hr/employees/{$temp->id}/work-assignments", ['assignment_type' => 'permanent', 'start_date' => '2026-10-15', 'client_company_id' => $company->id])->assertStatus(422);
        $this->as($this->token)->postJson("/api/v1/admin/hr/employees/{$temp->id}/work-assignments", $this->payload(['client_company_id' => 999999]))->assertStatus(422);

        $this->assertSame($assignments, EmployeeWorkAssignment::query()->count());
        $this->assertSame($audit, AuditLog::query()->count());
        $this->assertSame('temporary', $temp->fresh()->employment_type);

        // A Temporary registered as a REPLACEMENT is a different thing and keeps its own refusal / acknowledgement (U1).
        $permanent = $this->makeEmployee('active', 'permanent');
        $covered = $this->makeAssignment($permanent, $this->makeCompany('Epsilon Co'), null, 300, '2026-01-01');
        $this->as($this->token)->postJson("/api/v1/admin/hr/employees/{$temp->id}/work-assignments", ['assignment_type' => 'replacement', 'replaces_assignment_id' => $covered->id, 'start_date' => '2026-10-15', 'client_company_id' => $covered->client_company_id])
            ->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_REGISTERED_AS_REPLACEMENT');
    }

    public function test_a_permanent_assignment_does_not_audit_as_temporary_for_a_permanent_employee(): void
    {
        $permanent = $this->makeEmployee('active', 'permanent');
        $company = $this->makeCompany('Zeta Co');

        $id = $this->as($this->token)->postJson("/api/v1/admin/hr/employees/{$permanent->id}/work-assignments", $this->payload(['client_company_id' => $company->id]))->assertCreated()->json('data.id');

        $log = AuditLog::query()->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $id)->where('action', 'create')->firstOrFail();
        $this->assertArrayNotHasKey('temporary_employee_permanent_assignment', $log->metadata ?? []);
    }
}
