<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemporaryReplacementTest extends TestCase
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

    private function makeWorkLocation(): WorkLocation
    {
        $company = ClientCompany::query()->create(['name' => fake()->unique()->company(), 'status' => 'active']);

        return WorkLocation::query()->create([
            'client_company_id' => $company->id,
            'location_type' => 'client',
            'name' => 'Site '.fake()->unique()->numerify('###'),
            'status' => 'active',
        ]);
    }

    private function makeActiveAssignment(string $token, Employee $employee, WorkLocation $location): int
    {
        return $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/work-assignments", [
            'work_location_id' => $location->id,
            'start_date' => now()->toDateString(),
            'salary_amount' => 100,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
        ])->assertStatus(201)->json('data.id');
    }

    public function test_create_end_and_record_payments_for_a_temporary_replacement(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeWorkLocation();
        $originalEmployee = $this->makeEmployee();
        $replacementEmployee = $this->makeEmployee();
        $assignmentId = $this->makeActiveAssignment($token, $originalEmployee, $location);

        $created = $this->withToken($token)->postJson('/api/v1/admin/hr/temporary-replacements', [
            'work_assignment_id' => $assignmentId,
            'replacement_employee_id' => $replacementEmployee->id,
            'start_date' => now()->toDateString(),
            'daily_rate' => 20,
            'currency' => 'USD',
            'reason' => 'Sick leave',
        ])->assertStatus(201)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.replaced_employee_id', $originalEmployee->id)
            ->assertJsonPath('data.replacement_employee_id', $replacementEmployee->id)
            ->json('data.id');

        $this->withToken($token)->getJson('/api/v1/admin/hr/temporary-replacements')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->withToken($token)->postJson("/api/v1/admin/hr/temporary-replacements/{$created}/payments", [
            'payment_date' => now()->toDateString(),
            'amount' => 20,
        ])->assertStatus(201)->assertJsonCount(1, 'data.payments');

        $this->withToken($token)->postJson("/api/v1/admin/hr/temporary-replacements/{$created}/payments", [
            'payment_date' => now()->addDay()->toDateString(),
            'amount' => 20,
        ])->assertStatus(201)
            ->assertJsonCount(2, 'data.payments')
            ->assertJsonPath('data.total_paid', '40.00');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$created}/end", [
            'end_date' => now()->addDays(2)->toDateString(),
        ])->assertOk()->assertJsonPath('data.status', 'ended');

        // Ending doesn't erase the ledger.
        $this->withToken($token)->getJson('/api/v1/admin/hr/temporary-replacements')
            ->assertOk()->assertJsonCount(2, 'data.0.payments');
    }

    public function test_replacement_employee_must_be_active(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeWorkLocation();
        $originalEmployee = $this->makeEmployee();
        $assignmentId = $this->makeActiveAssignment($token, $originalEmployee, $location);
        $waitingReplacement = $this->makeEmployee('waiting');

        $this->withToken($token)->postJson('/api/v1/admin/hr/temporary-replacements', [
            'work_assignment_id' => $assignmentId,
            'replacement_employee_id' => $waitingReplacement->id,
            'start_date' => now()->toDateString(),
            'daily_rate' => 20,
            'currency' => 'USD',
        ])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ACTIVE');
    }

    public function test_replacement_work_assignment_must_be_active(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeWorkLocation();
        $originalEmployee = $this->makeEmployee();
        $replacementEmployee = $this->makeEmployee();
        $assignmentId = $this->makeActiveAssignment($token, $originalEmployee, $location);

        $this->withToken($token)->patchJson("/api/v1/admin/hr/work-assignments/{$assignmentId}/end", [
            'end_date' => now()->toDateString(),
        ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/admin/hr/temporary-replacements', [
            'work_assignment_id' => $assignmentId,
            'replacement_employee_id' => $replacementEmployee->id,
            'start_date' => now()->toDateString(),
            'daily_rate' => 20,
            'currency' => 'USD',
        ])->assertStatus(422)->assertJsonPath('error_code', 'WORK_ASSIGNMENT_NOT_ACTIVE');
    }

    public function test_employee_cannot_replace_their_own_assignment(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeWorkLocation();
        $employee = $this->makeEmployee();
        $assignmentId = $this->makeActiveAssignment($token, $employee, $location);

        $this->withToken($token)->postJson('/api/v1/admin/hr/temporary-replacements', [
            'work_assignment_id' => $assignmentId,
            'replacement_employee_id' => $employee->id,
            'start_date' => now()->toDateString(),
            'daily_rate' => 20,
            'currency' => 'USD',
        ])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_CANNOT_REPLACE_SELF');
    }

    public function test_replacement_can_be_for_a_different_company_than_their_own_assignment(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $locationA = $this->makeWorkLocation();
        $locationB = $this->makeWorkLocation();
        $originalEmployee = $this->makeEmployee();
        $replacementEmployee = $this->makeEmployee();

        // The replacement already has their own active assignment at Company B's location.
        $this->makeActiveAssignment($token, $replacementEmployee, $locationB);
        $assignmentAtA = $this->makeActiveAssignment($token, $originalEmployee, $locationA);

        $this->withToken($token)->postJson('/api/v1/admin/hr/temporary-replacements', [
            'work_assignment_id' => $assignmentAtA,
            'replacement_employee_id' => $replacementEmployee->id,
            'start_date' => now()->toDateString(),
            'daily_rate' => 25,
            'currency' => 'USD',
        ])->assertStatus(201)->assertJsonPath('data.status', 'active');
    }

    public function test_ending_a_temporary_replacement_preserves_payment_history(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeWorkLocation();
        $originalEmployee = $this->makeEmployee();
        $replacementEmployee = $this->makeEmployee();
        $assignmentId = $this->makeActiveAssignment($token, $originalEmployee, $location);

        $created = $this->withToken($token)->postJson('/api/v1/admin/hr/temporary-replacements', [
            'work_assignment_id' => $assignmentId,
            'replacement_employee_id' => $replacementEmployee->id,
            'start_date' => now()->toDateString(),
            'daily_rate' => 20,
            'currency' => 'USD',
        ])->assertStatus(201)->json('data.id');

        $this->withToken($token)->postJson("/api/v1/admin/hr/temporary-replacements/{$created}/payments", [
            'payment_date' => now()->toDateString(),
            'amount' => 20,
        ])->assertStatus(201);

        $this->withToken($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$created}/end", [
            'end_date' => now()->toDateString(),
        ])->assertOk();

        // Blocked from ending twice.
        $this->withToken($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$created}/end", [
            'end_date' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_REPLACEMENT_NOT_ACTIVE');

        // Payments can still be logged after coverage ends (final settlement).
        $this->withToken($token)->postJson("/api/v1/admin/hr/temporary-replacements/{$created}/payments", [
            'payment_date' => now()->toDateString(),
            'amount' => 5,
        ])->assertStatus(201)->assertJsonCount(2, 'data.payments');

        $this->assertDatabaseCount('temporary_replacement_payments', 2);
    }
}
