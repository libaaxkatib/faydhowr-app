<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\ClientCompany;
use App\Models\EmployeeWorkAssignment;
use App\Models\HrPolicy;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Adding an employee to a SECOND company needs, while the multi-company policy is ON, an explicit confirmation that names the employee, the
 * current company and the new one - on the company screen AND on the employee page, enforced by the server. With the policy OFF the second
 * company is refused outright; the confirmation never switches the policy and never touches the existing assignment.
 */
class AdditionalCompanyConfirmationTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private ClientCompany $a;

    private ClientCompany $b;

    private string $hr;

    /** Only the Super Admin switches the multi-company policy (owner decision 2026-10-29); the HR Manager then works under whichever state it is in. */
    private string $super;

    private Position $position;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->makeCompany('Alpha Hospital');
        $this->b = $this->makeCompany('Beta School');
        $this->position = Position::query()->create(['name' => 'Cleaner']);
        $this->hr = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);
        [, $this->super] = $this->superAdminToken();
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function body(array $over = []): array
    {
        return array_replace(['client_company_id' => $this->b->id, 'position_id' => $this->position->id, 'assignment_type' => 'permanent', 'start_date' => '2026-10-05', 'salary_amount' => 150, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly'], $over);
    }

    public function test_the_employee_page_asks_for_confirmation_while_on_and_saves_only_after_it(): void
    {
        $employee = $this->makeEmployee();
        $first = $this->makeAssignment($employee, $this->a, null, 300, '2026-01-01');
        $this->as($this->super)->putJson('/api/v1/admin/hr/policies/'.HrPolicy::MULTI_COMPANY_ASSIGNMENTS, ['enabled' => true, 'reason' => 'A cleaner works two sites'])->assertOk();
        $url = "/api/v1/admin/hr/employees/{$employee->id}/work-assignments";

        $r = $this->as($this->hr)->postJson($url, $this->body())->assertUnprocessable()->assertJsonPath('error_code', 'ADDITIONAL_COMPANY_CONFIRMATION_REQUIRED');
        $this->assertStringContainsString('Alpha Hospital', $r->json('message'));
        $this->assertStringContainsString('Beta School', $r->json('message'));
        $this->assertStringContainsString($employee->full_name, $r->json('message'));
        $this->assertSame(1, EmployeeWorkAssignment::query()->where('employee_id', $employee->id)->count());
        $this->as($this->hr)->postJson($url, $this->body(['confirm_additional_company' => false]))->assertUnprocessable()->assertJsonPath('error_code', 'ADDITIONAL_COMPANY_CONFIRMATION_REQUIRED');

        $this->as($this->hr)->postJson($url, $this->body(['confirm_additional_company' => true]))->assertCreated();

        $this->assertSame(2, EmployeeWorkAssignment::query()->where('employee_id', $employee->id)->where('status', 'active')->count());
        $kept = $first->fresh();
        $this->assertSame([$this->a->id, '300.00'], [$kept->client_company_id, (string) $kept->salary_amount], 'the existing assignment is untouched');
        $this->assertTrue(HrPolicy::isEnabled(HrPolicy::MULTI_COMPANY_ASSIGNMENTS), 'the confirmation never changes the policy');
    }

    public function test_while_off_the_second_company_is_refused_whatever_the_confirmation_says(): void
    {
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->a, null, 300, '2026-01-01');
        $url = "/api/v1/admin/hr/employees/{$employee->id}/work-assignments";

        $this->as($this->hr)->postJson($url, $this->body(['confirm_additional_company' => true]))->assertUnprocessable()->assertJsonPath('error_code', 'EMPLOYEE_ALREADY_ASSIGNED_ELSEWHERE');
        $this->assertFalse(HrPolicy::isEnabled(HrPolicy::MULTI_COMPANY_ASSIGNMENTS));
        $this->assertSame(1, EmployeeWorkAssignment::query()->where('employee_id', $employee->id)->count());
    }

    public function test_no_confirmation_is_asked_for_a_first_company_or_when_there_is_no_overlap(): void
    {
        $this->as($this->super)->putJson('/api/v1/admin/hr/policies/'.HrPolicy::MULTI_COMPANY_ASSIGNMENTS, ['enabled' => true, 'reason' => 'A cleaner works two sites'])->assertOk();
        $fresh = $this->makeEmployee();
        $this->as($this->hr)->postJson("/api/v1/admin/hr/employees/{$fresh->id}/work-assignments", $this->body())->assertCreated();

        $ended = $this->makeEmployee();
        $old = $this->makeAssignment($ended, $this->a, null, 300, '2026-01-01');
        $old->update(['end_date' => '2026-09-30', 'status' => 'ended']);
        $this->as($this->hr)->postJson("/api/v1/admin/hr/employees/{$ended->id}/work-assignments", $this->body(['start_date' => '2026-10-05']))->assertCreated();
    }
}
