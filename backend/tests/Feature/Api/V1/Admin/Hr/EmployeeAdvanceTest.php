<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(string $status = 'active'): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name(),
            'phone' => fake()->unique()->e164PhoneNumber(),
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'status' => $status,
            'application_date' => now()->toDateString(),
        ]);
    }

    public function test_recording_an_advance_requires_active_employee(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $applicant = $this->makeEmployee('applicant');

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$applicant->id}/advances", [
            'advance_date' => now()->toDateString(),
            'amount' => 50,
            'currency' => 'USD',
            'payroll_period' => now()->format('Y-m'),
            'reason' => 'Emergency advance',
        ])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ACTIVE');
    }

    public function test_multiple_advances_in_the_same_month_are_preserved(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee();
        $period = now()->format('Y-m');

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/advances", [
            'advance_date' => now()->toDateString(),
            'amount' => 50,
            'currency' => 'USD',
            'payroll_period' => $period,
            'reason' => 'Emergency advance',
        ])->assertStatus(201)->assertJsonCount(1, 'data.advances');

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/advances", [
            'advance_date' => now()->toDateString(),
            'amount' => 30,
            'currency' => 'USD',
            'payroll_period' => $period,
            'reason' => 'Second advance',
        ])->assertStatus(201)->assertJsonCount(2, 'data.advances');

        $this->assertDatabaseCount('employee_advances', 2);
    }

    public function test_multiple_advances_in_different_months_are_preserved(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee();

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
        ])->assertStatus(201)->assertJsonCount(2, 'data.advances');

        $this->assertDatabaseHas('employee_advances', ['employee_id' => $employee->id, 'payroll_period' => '2026-01', 'amount' => 50]);
        $this->assertDatabaseHas('employee_advances', ['employee_id' => $employee->id, 'payroll_period' => '2026-02', 'amount' => 30]);
    }

    public function test_invalid_payroll_period_is_rejected(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee();

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/advances", [
            'advance_date' => now()->toDateString(),
            'amount' => 50,
            'currency' => 'USD',
            'payroll_period' => 'not-a-period',
            'reason' => 'Emergency advance',
        ])->assertStatus(422)->assertJsonValidationErrors('payroll_period');
    }

    public function test_advances_are_never_mixed_into_payments(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee();

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/advances", [
            'advance_date' => now()->toDateString(),
            'amount' => 50,
            'currency' => 'USD',
            'payroll_period' => now()->format('Y-m'),
            'reason' => 'Emergency advance',
        ])->assertStatus(201);

        $response = $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employee->id}")->assertOk();

        $this->assertCount(1, $response->json('data.advances'));
        $this->assertCount(0, $response->json('data.payments'));
        $this->assertCount(0, $response->json('data.penalties'));
    }
}
