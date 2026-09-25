<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeRecordsTest extends TestCase
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

    public function test_recording_a_leave_requires_active_and_accumulates_history(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $applicant = $this->makeEmployee('applicant');

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$applicant->id}/leaves", [
            'leave_type' => 'annual',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
        ])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ACTIVE');

        $employee = $this->makeEmployee();
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/leaves", [
            'leave_type' => 'annual',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
        ])->assertStatus(201)->assertJsonCount(1, 'data.leaves');

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/leaves", [
            'leave_type' => 'sick',
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(11)->toDateString(),
        ])->assertStatus(201)->assertJsonCount(2, 'data.leaves');

        $this->assertDatabaseCount('employee_leaves', 2);
    }

    public function test_leave_end_date_must_not_precede_start_date(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee();

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/leaves", [
            'leave_type' => 'annual',
            'start_date' => now()->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('end_date');
    }

    public function test_recording_a_performance_review_requires_active_and_accumulates_history(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $applicant = $this->makeEmployee('applicant');

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$applicant->id}/performance-reviews", [
            'review_date' => now()->toDateString(),
            'rating' => 'good',
        ])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ACTIVE');

        $employee = $this->makeEmployee();
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/performance-reviews", [
            'review_date' => now()->toDateString(),
            'rating' => 'excellent',
            'notes' => 'Great quarter.',
        ])->assertStatus(201)->assertJsonCount(1, 'data.performance_reviews');

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/performance-reviews", [
            'review_date' => now()->addMonths(3)->toDateString(),
            'rating' => 'needs_improvement',
        ])->assertStatus(201)->assertJsonCount(2, 'data.performance_reviews');

        $this->assertDatabaseCount('employee_performance_reviews', 2);
    }

    public function test_recording_a_payment_requires_active_and_accumulates_history(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $applicant = $this->makeEmployee('applicant');

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$applicant->id}/payments", [
            'payment_date' => now()->toDateString(),
            'amount' => 500,
            'currency' => 'USD',
        ])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ACTIVE');

        $employee = $this->makeEmployee();
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/payments", [
            'payment_date' => now()->toDateString(),
            'amount' => 500,
            'currency' => 'USD',
        ])->assertStatus(201)->assertJsonCount(1, 'data.payments');

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/payments", [
            'payment_date' => now()->addMonth()->toDateString(),
            'amount' => 500,
            'currency' => 'USD',
        ])->assertStatus(201)->assertJsonCount(2, 'data.payments');

        $this->assertDatabaseCount('employee_payments', 2);
    }

    public function test_current_salary_reflects_the_active_assignment_and_is_null_without_one(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee();

        $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.current_salary', null);

        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/work-assignments", [
            'work_location_id' => $office->id,
            'start_date' => now()->toDateString(),
            'salary_amount' => 750,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
        ])->assertStatus(201);

        $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.current_salary.amount', '750.00')
            ->assertJsonPath('data.current_salary.currency', 'USD')
            ->assertJsonPath('data.current_salary.frequency', 'monthly');
    }
}
