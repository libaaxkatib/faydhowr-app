<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeePenaltyTest extends TestCase
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

    public function test_recording_a_penalty_requires_active_and_accumulates_history(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $applicant = $this->makeEmployee('applicant');

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$applicant->id}/penalties", [
            'penalty_date' => now()->toDateString(),
            'reason' => 'Disciplinary penalty',
            'deduction_amount' => 10,
            'currency' => 'USD',
            'payroll_period' => now()->format('Y-m'),
        ])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ACTIVE');

        $employee = $this->makeEmployee();
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/penalties", [
            'penalty_date' => now()->toDateString(),
            'reason' => 'Disciplinary penalty',
            'deduction_amount' => 10,
            'currency' => 'USD',
            'payroll_period' => now()->format('Y-m'),
        ])->assertStatus(201)->assertJsonCount(1, 'data.penalties');

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/penalties", [
            'penalty_date' => now()->addDays(10)->toDateString(),
            'reason' => 'Other penalty',
            'deduction_amount' => 15,
            'currency' => 'USD',
            'payroll_period' => now()->format('Y-m'),
        ])->assertStatus(201)->assertJsonCount(2, 'data.penalties');

        $this->assertDatabaseCount('employee_penalties', 2);
    }

    public function test_invalid_payroll_period_format_is_rejected(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee();

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/penalties", [
            'penalty_date' => now()->toDateString(),
            'reason' => 'Disciplinary penalty',
            'deduction_amount' => 10,
            'currency' => 'USD',
            'payroll_period' => '2026/09',
        ])->assertStatus(422)->assertJsonValidationErrors('payroll_period');
    }

    public function test_penalties_are_never_mixed_into_payments(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee();

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/penalties", [
            'penalty_date' => now()->toDateString(),
            'reason' => 'Disciplinary penalty',
            'deduction_amount' => 10,
            'currency' => 'USD',
            'payroll_period' => now()->format('Y-m'),
        ])->assertStatus(201);

        $response = $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employee->id}")->assertOk();

        $this->assertCount(1, $response->json('data.penalties'));
        $this->assertCount(0, $response->json('data.payments'));
    }
}
