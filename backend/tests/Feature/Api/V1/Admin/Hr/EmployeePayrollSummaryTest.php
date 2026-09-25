<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeePayrollSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name(),
            'phone' => fake()->unique()->e164PhoneNumber(),
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'status' => 'active',
            'application_date' => now()->toDateString(),
        ]);
    }

    private function makeClientLocation(): WorkLocation
    {
        $company = ClientCompany::query()->create(['name' => fake()->unique()->company(), 'status' => 'active']);

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
            'start_date' => '2026-01-01',
            'salary_amount' => $salaryAmount,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
        ])->assertStatus(201)->json('data.id');
    }

    private function markAttendance(string $token, Employee $employee, string $date, string $status): void
    {
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/attendance", [
            'date' => $date,
            'status' => $status,
        ])->assertOk();
    }

    public function test_daily_rate_is_correct_for_28_29_30_and_31_day_months(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeClientLocation();

        // 31-day month: January.
        $jan = $this->makeEmployee();
        $janAssignment = $this->makeAssignment($token, $jan, $location->id, 310);
        $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$jan->id}/payroll-summary?work_assignment_id={$janAssignment}&period=2026-01")
            ->assertOk()
            ->assertJsonPath('data.days_in_month', 31)
            ->assertJsonPath('data.daily_rate', '10.00');

        // 30-day month: April.
        $apr = $this->makeEmployee();
        $aprAssignment = $this->makeAssignment($token, $apr, $location->id, 300);
        $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$apr->id}/payroll-summary?work_assignment_id={$aprAssignment}&period=2026-04")
            ->assertOk()
            ->assertJsonPath('data.days_in_month', 30)
            ->assertJsonPath('data.daily_rate', '10.00');

        // 28-day month: February 2026 (not a leap year).
        $febNonLeap = $this->makeEmployee();
        $febAssignment = $this->makeAssignment($token, $febNonLeap, $location->id, 280);
        $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$febNonLeap->id}/payroll-summary?work_assignment_id={$febAssignment}&period=2026-02")
            ->assertOk()
            ->assertJsonPath('data.days_in_month', 28)
            ->assertJsonPath('data.daily_rate', '10.00');

        // 29-day month: February 2028 (leap year).
        $febLeap = $this->makeEmployee();
        $febLeapAssignment = $this->makeAssignment($token, $febLeap, $location->id, 290);
        $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$febLeap->id}/payroll-summary?work_assignment_id={$febLeapAssignment}&period=2028-02")
            ->assertOk()
            ->assertJsonPath('data.days_in_month', 29)
            ->assertJsonPath('data.daily_rate', '10.00');
    }

    public function test_full_worked_example_matches_confirmed_spec(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeClientLocation();
        $employee = $this->makeEmployee();
        $assignmentId = $this->makeAssignment($token, $employee, $location->id, 300);

        // 1 absent day in a 30-day month (April) = $10 absence deduction.
        $this->markAttendance($token, $employee, '2026-04-05', 'absent');

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/penalties", [
            'penalty_date' => '2026-04-10',
            'reason' => 'Disciplinary penalty',
            'deduction_amount' => 20,
            'currency' => 'USD',
            'payroll_period' => '2026-04',
        ])->assertStatus(201);

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/advances", [
            'advance_date' => '2026-04-12',
            'amount' => 50,
            'currency' => 'USD',
            'payroll_period' => '2026-04',
            'reason' => 'Emergency advance',
        ])->assertStatus(201);

        $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employee->id}/payroll-summary?work_assignment_id={$assignmentId}&period=2026-04")
            ->assertOk()
            ->assertJsonPath('data.monthly_salary', '300.00')
            ->assertJsonPath('data.absent_days', 1)
            ->assertJsonPath('data.absence_deduction', '10.00')
            ->assertJsonPath('data.penalty_deduction', '20.00')
            ->assertJsonPath('data.advance_deduction', '50.00')
            ->assertJsonPath('data.net_payable', '220.00');
    }

    public function test_advance_from_another_payroll_period_does_not_leak_into_the_calculation(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeClientLocation();
        $employee = $this->makeEmployee();
        $assignmentId = $this->makeAssignment($token, $employee, $location->id, 300);

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/advances", [
            'advance_date' => '2026-01-15',
            'amount' => 50,
            'currency' => 'USD',
            'payroll_period' => '2026-01',
            'reason' => 'January advance',
        ])->assertStatus(201);

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/advances", [
            'advance_date' => '2026-02-15',
            'amount' => 30,
            'currency' => 'USD',
            'payroll_period' => '2026-02',
            'reason' => 'February advance',
        ])->assertStatus(201);

        $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employee->id}/payroll-summary?work_assignment_id={$assignmentId}&period=2026-02")
            ->assertOk()
            ->assertJsonPath('data.advance_deduction', '30.00')
            ->assertJsonCount(1, 'data.advances');
    }

    public function test_multiple_advances_in_the_same_period_are_summed(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeClientLocation();
        $employee = $this->makeEmployee();
        $assignmentId = $this->makeAssignment($token, $employee, $location->id, 300);

        foreach ([30, 20] as $amount) {
            $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/advances", [
                'advance_date' => '2026-05-10',
                'amount' => $amount,
                'currency' => 'USD',
                'payroll_period' => '2026-05',
                'reason' => 'Advance',
            ])->assertStatus(201);
        }

        $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employee->id}/payroll-summary?work_assignment_id={$assignmentId}&period=2026-05")
            ->assertOk()
            ->assertJsonPath('data.advance_deduction', '50.00')
            ->assertJsonCount(2, 'data.advances');
    }

    public function test_client_assignment_payload_never_contains_an_overtime_figure(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeClientLocation();
        $employee = $this->makeEmployee();
        $assignmentId = $this->makeAssignment($token, $employee, $location->id, 300);

        $response = $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employee->id}/payroll-summary?work_assignment_id={$assignmentId}&period=2026-04")
            ->assertOk()
            ->assertJsonPath('data.location_type', 'client');

        $this->assertArrayNotHasKey('overtime', $response->json('data'));
    }

    public function test_office_assignment_payload_also_never_contains_a_fabricated_overtime_figure(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();
        $employee = $this->makeEmployee();
        $assignmentId = $this->makeAssignment($token, $employee, $office->id, 300);

        $response = $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employee->id}/payroll-summary?work_assignment_id={$assignmentId}&period=2026-04")
            ->assertOk()
            ->assertJsonPath('data.location_type', 'office');

        $this->assertArrayNotHasKey('overtime', $response->json('data'));
    }

    public function test_work_assignment_must_belong_to_the_requested_employee(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeClientLocation();
        $employeeA = $this->makeEmployee();
        $employeeB = $this->makeEmployee();
        $assignmentId = $this->makeAssignment($token, $employeeA, $location->id, 300);

        $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employeeB->id}/payroll-summary?work_assignment_id={$assignmentId}&period=2026-04")
            ->assertStatus(422)->assertJsonPath('error_code', 'WORK_ASSIGNMENT_NOT_FOUND_FOR_EMPLOYEE');
    }
}
