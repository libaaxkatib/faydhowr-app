<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetPayrollRollupTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(?int $departmentId = null): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name(),
            'phone' => fake()->unique()->e164PhoneNumber(),
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'department_id' => $departmentId,
            'status' => 'active',
            'application_date' => now()->toDateString(),
        ]);
    }

    private function makeClientLocation(?ClientCompany $company = null): WorkLocation
    {
        $company ??= ClientCompany::query()->create(['name' => fake()->unique()->company(), 'status' => 'active']);

        return WorkLocation::query()->create([
            'client_company_id' => $company->id,
            'location_type' => 'client',
            'name' => 'Site '.fake()->unique()->numerify('###'),
            'status' => 'active',
        ]);
    }

    private function makeAssignment(string $token, Employee $employee, int $workLocationId, float $salaryAmount): int
    {
        return $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/work-assignments", [
            'work_location_id' => $workLocationId,
            'start_date' => '2026-04-01',
            'salary_amount' => $salaryAmount,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
        ])->assertStatus(201)->json('data.id');
    }

    public function test_payroll_rollup_matches_the_individual_payroll_summary_exactly(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeClientLocation();
        $employee = $this->makeEmployee();
        $assignmentId = $this->makeAssignment($token, $employee, $location->id, 300);

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/penalties", [
            'penalty_date' => '2026-04-10', 'reason' => 'Late', 'deduction_amount' => 15,
            'currency' => 'USD', 'payroll_period' => '2026-04',
        ])->assertStatus(201);
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/advances", [
            'advance_date' => '2026-04-11', 'amount' => 25, 'currency' => 'USD',
            'payroll_period' => '2026-04', 'reason' => 'Emergency',
        ])->assertStatus(201);
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/attendance", [
            'date' => '2026-04-05', 'status' => 'absent',
        ])->assertOk();

        $summary = $this->withToken($token)
            ->getJson("/api/v1/admin/hr/employees/{$employee->id}/payroll-summary?work_assignment_id={$assignmentId}&period=2026-04")
            ->assertOk()->json('data');

        $rollup = $this->withToken($token)
            ->getJson('/api/v1/admin/hr/reports/payroll-rollup?period=2026-04')
            ->assertOk()->json('data');

        $row = collect($rollup)->firstWhere('employee_id', $employee->id);

        $this->assertNotNull($row);
        $this->assertSame($summary['monthly_salary'], $row['monthly_salary']);
        $this->assertSame($summary['days_in_month'], $row['days_in_month']);
        $this->assertSame($summary['daily_rate'], $row['daily_rate']);
        $this->assertSame($summary['absent_days'], $row['absent_days']);
        $this->assertSame($summary['absence_deduction'], $row['absence_deduction']);
        $this->assertSame($summary['penalty_deduction'], $row['penalty_deduction']);
        $this->assertSame($summary['advance_deduction'], $row['advance_deduction']);
        $this->assertSame($summary['net_payable'], $row['net_payable']);
        $this->assertArrayNotHasKey('overtime', $row);
    }

    public function test_payroll_rollup_filters_by_client_company_and_department(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $companyA = ClientCompany::query()->create(['name' => 'Company A', 'status' => 'active']);
        $companyB = ClientCompany::query()->create(['name' => 'Company B', 'status' => 'active']);
        $locationA = $this->makeClientLocation($companyA);
        $locationB = $this->makeClientLocation($companyB);
        $department = Department::query()->create(['name' => 'Ops']);

        $employeeA = $this->makeEmployee($department->id);
        $this->makeAssignment($token, $employeeA, $locationA->id, 300);

        $employeeB = $this->makeEmployee();
        $this->makeAssignment($token, $employeeB, $locationB->id, 300);

        $rollupForCompanyA = $this->withToken($token)
            ->getJson("/api/v1/admin/hr/reports/payroll-rollup?period=2026-04&client_company_id={$companyA->id}")
            ->assertOk()->json('data');

        $this->assertCount(1, $rollupForCompanyA);
        $this->assertSame($employeeA->id, $rollupForCompanyA[0]['employee_id']);

        $rollupForDepartment = $this->withToken($token)
            ->getJson("/api/v1/admin/hr/reports/payroll-rollup?period=2026-04&department_id={$department->id}")
            ->assertOk()->json('data');

        $this->assertCount(1, $rollupForDepartment);
        $this->assertSame($employeeA->id, $rollupForDepartment[0]['employee_id']);
    }

    public function test_payroll_rollup_requires_a_valid_period(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/hr/reports/payroll-rollup?period=2026-13')
            ->assertStatus(422);

        $this->withToken($token)->getJson('/api/v1/admin/hr/reports/payroll-rollup')
            ->assertStatus(422);
    }

    public function test_payroll_rollup_returns_empty_collection_when_nothing_matches(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/payroll-rollup?period=2026-04')
            ->assertOk();

        $this->assertSame([], $response->json('data'));
    }

    public function test_payroll_rollup_is_rejected_without_hr_reports_view_permission(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/hr/reports/payroll-rollup?period=2026-04')
            ->assertStatus(403);
    }
}
