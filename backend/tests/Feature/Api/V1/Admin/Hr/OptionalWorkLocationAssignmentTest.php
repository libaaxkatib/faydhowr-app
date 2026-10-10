<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\EmployeeWorkAssignment;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Approved decision: a company assignment must not require a work location.
 * The company lives on the assignment; the location is filled in when known.
 */
class OptionalWorkLocationAssignmentTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'start_date' => '2026-10-01',
            'salary_amount' => 120,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
        ], $overrides);
    }

    private function url(int $employeeId): string
    {
        return "/api/v1/admin/hr/employees/{$employeeId}/work-assignments";
    }

    public function test_a_company_assignment_can_be_created_without_a_work_location(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany('Alpha Hospital');
        $employee = $this->makeEmployee();

        $response = $this->withToken($token)
            ->postJson($this->url($employee->id), $this->payload(['client_company_id' => $company->id]));

        $response->assertCreated()
            ->assertJsonPath('data.client_company_id', $company->id)
            ->assertJsonPath('data.work_location_id', null)
            ->assertJsonPath('data.client_company_name', 'Alpha Hospital')
            ->assertJsonPath('data.location_type', 'client');
        $this->assertDatabaseHas('employee_work_assignments', [
            'employee_id' => $employee->id, 'client_company_id' => $company->id, 'work_location_id' => null,
        ]);
        $this->assertDatabaseCount('work_locations', 1); // only the seeded office: no placeholder invented
    }

    public function test_an_assignment_needs_a_company_or_a_location(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();

        $this->withToken($token)
            ->postJson($this->url($employee->id), $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_company_id');
    }

    public function test_a_known_location_fills_in_the_company_automatically(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();
        $location = $this->makeClientLocation($company);
        $employee = $this->makeEmployee();

        $this->withToken($token)
            ->postJson($this->url($employee->id), $this->payload(['work_location_id' => $location->id]))
            ->assertCreated()
            ->assertJsonPath('data.client_company_id', $company->id)
            ->assertJsonPath('data.work_location_id', $location->id);
    }

    public function test_a_location_from_a_different_company_is_rejected(): void
    {
        [, $token] = $this->superAdminToken();
        $a = $this->makeCompany('Company A');
        $b = $this->makeCompany('Company B');
        $locationOfB = $this->makeClientLocation($b);
        $employee = $this->makeEmployee();

        $this->withToken($token)
            ->postJson($this->url($employee->id), $this->payload(['client_company_id' => $a->id, 'work_location_id' => $locationOfB->id]))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'LOCATION_COMPANY_MISMATCH');
    }

    public function test_the_office_assignment_cannot_carry_a_company(): void
    {
        [, $token] = $this->superAdminToken();
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();
        $employee = $this->makeEmployee();

        $this->withToken($token)
            ->postJson($this->url($employee->id), $this->payload(['work_location_id' => $office->id, 'client_company_id' => $this->makeCompany()->id]))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OFFICE_ASSIGNMENT_HAS_NO_COMPANY');
    }

    public function test_the_same_employee_cannot_get_two_active_company_only_assignments_at_one_company(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();
        $employee = $this->makeEmployee();

        $this->withToken($token)->postJson($this->url($employee->id), $this->payload(['client_company_id' => $company->id]))->assertCreated();

        $this->withToken($token)
            ->postJson($this->url($employee->id), $this->payload(['client_company_id' => $company->id]))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'EMPLOYEE_ALREADY_ASSIGNED_AT_COMPANY');
        $this->assertSame(1, EmployeeWorkAssignment::query()->where('employee_id', $employee->id)->count());
    }

    public function test_a_known_location_cannot_duplicate_an_existing_company_only_assignment(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();
        $location = $this->makeClientLocation($company);
        $employee = $this->makeEmployee();

        $this->withToken($token)->postJson($this->url($employee->id), $this->payload(['client_company_id' => $company->id]))->assertCreated();

        $this->withToken($token)
            ->postJson($this->url($employee->id), $this->payload(['work_location_id' => $location->id]))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'EMPLOYEE_ALREADY_ASSIGNED_AT_COMPANY');
    }

    public function test_the_same_location_still_cannot_be_assigned_twice(): void
    {
        [, $token] = $this->superAdminToken();
        $location = $this->makeClientLocation($this->makeCompany());
        $employee = $this->makeEmployee();

        $this->withToken($token)->postJson($this->url($employee->id), $this->payload(['work_location_id' => $location->id]))->assertCreated();
        $this->withToken($token)
            ->postJson($this->url($employee->id), $this->payload(['work_location_id' => $location->id]))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'EMPLOYEE_ALREADY_ASSIGNED_AT_LOCATION');
    }

    public function test_the_work_location_can_be_completed_later_but_not_swapped(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();
        $first = $this->makeClientLocation($company, 'Site One');
        $second = $this->makeClientLocation($company, 'Site Two');
        $other = $this->makeClientLocation($this->makeCompany('Other Co'), 'Elsewhere');
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $company);

        $this->withToken($token)->putJson("/api/v1/admin/hr/work-assignments/{$assignment->id}", ['work_location_id' => $other->id])
            ->assertStatus(422)->assertJsonPath('error_code', 'LOCATION_COMPANY_MISMATCH');

        $this->withToken($token)->putJson("/api/v1/admin/hr/work-assignments/{$assignment->id}", ['work_location_id' => $first->id])
            ->assertOk()->assertJsonPath('data.work_location_id', $first->id);

        $this->withToken($token)->putJson("/api/v1/admin/hr/work-assignments/{$assignment->id}", ['work_location_id' => $second->id])
            ->assertStatus(422)->assertJsonPath('error_code', 'ASSIGNMENT_LOCATION_ALREADY_SET');

        $this->assertSame($first->id, $assignment->refresh()->work_location_id);
        $this->assertSame($company->id, $assignment->client_company_id);
    }

    public function test_a_company_with_assignments_cannot_be_deleted(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();
        $this->makeAssignment($this->makeEmployee(), $company);

        $this->withToken($token)->deleteJson("/api/v1/admin/hr/client-companies/{$company->id}")
            ->assertStatus(422)->assertJsonPath('error_code', 'CLIENT_COMPANY_HAS_ASSIGNMENTS');
        $this->assertDatabaseHas('client_companies', ['id' => $company->id]);
    }

    public function test_company_filtered_payroll_rollup_includes_assignments_without_a_location(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany('Rollup Co');
        $this->makeAssignment($this->makeEmployee(), $company, null, 300, '2026-01-01');
        $this->makeAssignment($this->makeEmployee(), $this->makeCompany('Someone Else'), null, 500, '2026-01-01');

        $response = $this->withToken($token)
            ->getJson("/api/v1/admin/hr/reports/payroll-rollup?period=2026-04&client_company_id={$company->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data.rows') ?? $response->json('data'));
    }

    public function test_existing_location_assignments_keep_their_workplace_and_gain_the_company(): void
    {
        $company = $this->makeCompany();
        $location = $this->makeClientLocation($company);
        $assignment = $this->makeAssignment($this->makeEmployee(), null, $location);

        $this->assertSame($location->id, $assignment->work_location_id);
        $this->assertSame($company->id, $assignment->client_company_id);
        $this->assertSame($company->id, $assignment->refresh()->clientCompany->id);
    }

    public function test_the_hr_report_counts_assignments_that_have_no_workplace_yet(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();
        $location = $this->makeClientLocation($company);
        $this->makeAssignment($this->makeEmployee(), $company);
        $this->makeAssignment($this->makeEmployee(), $company);
        $this->makeAssignment($this->makeEmployee(), null, $location);

        $summary = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/summary')->assertOk();

        $this->assertSame(2, $summary->json('data.assignments_without_workplace'));
        $this->assertSame(3, (int) collect($summary->json('data.company_salary_totals'))->sum('total_assignments'));
    }
}
