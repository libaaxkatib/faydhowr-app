<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeSeparationTest extends TestCase
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

    public function test_marking_active_employee_inactive_requires_a_reason_and_creates_separation_record(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee('active');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/separate", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason', 'separation_date']);

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/separate", [
            'reason' => 'resigned',
            'separation_date' => now()->toDateString(),
            'notes' => 'Moving to another city.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.latest_separation.reason', 'resigned')
            ->assertJsonPath('data.latest_separation.rehire_eligible', true);

        $this->assertDatabaseHas('employee_separations', [
            'employee_id' => $employee->id,
            'reason' => 'resigned',
            'rehire_eligible' => true,
        ]);
        $this->assertDatabaseHas('employee_status_histories', [
            'employee_id' => $employee->id,
            'from_status' => 'active',
            'to_status' => 'inactive',
        ]);
    }

    public function test_cannot_separate_an_already_inactive_employee(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee('inactive');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/separate", [
            'reason' => 'resigned',
            'separation_date' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_ALREADY_INACTIVE');
    }

    public function test_rehire_reactivates_a_former_employee_directly_to_active(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee('active');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/separate", [
            'reason' => 'terminated',
            'separation_date' => now()->toDateString(),
            'rehire_eligible' => false,
        ])->assertOk();

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/rehire", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.pipeline_stage', null);

        $this->assertDatabaseHas('employee_status_histories', [
            'employee_id' => $employee->id,
            'from_status' => 'inactive',
            'to_status' => 'active',
        ]);
    }

    public function test_cannot_rehire_an_employee_who_is_not_inactive(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee('active');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/rehire", [])
            ->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_SEPARATED');
    }

    public function test_rehire_preserves_separation_history(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee('active');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/separate", [
            'reason' => 'contract_ended',
            'separation_date' => now()->toDateString(),
        ])->assertOk();

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/rehire", [])->assertOk();

        // Separate again - a second, independent separation row, first one untouched.
        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/separate", [
            'reason' => 'resigned',
            'separation_date' => now()->toDateString(),
        ])->assertOk();

        $this->assertDatabaseCount('employee_separations', 2);
        $this->assertDatabaseHas('employee_separations', ['employee_id' => $employee->id, 'reason' => 'contract_ended']);
        $this->assertDatabaseHas('employee_separations', ['employee_id' => $employee->id, 'reason' => 'resigned']);
    }

    public function test_marking_employee_as_supervisor_requires_active_status(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $waitingEmployee = $this->makeEmployee('waiting');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$waitingEmployee->id}/supervisor", [
            'is_supervisor' => true,
        ])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ACTIVE');

        $activeEmployee = $this->makeEmployee('active');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$activeEmployee->id}/supervisor", [
            'is_supervisor' => true,
        ])->assertOk()
            ->assertJsonPath('data.is_supervisor', true);

        $this->assertNotNull($activeEmployee->refresh()->supervisor_since);
    }

    public function test_removing_supervisor_status_does_not_require_active_status(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee('active');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/supervisor", ['is_supervisor' => true])->assertOk();
        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/separate", [
            'reason' => 'resigned',
            'separation_date' => now()->toDateString(),
        ])->assertOk();

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/supervisor", ['is_supervisor' => false])
            ->assertOk()
            ->assertJsonPath('data.is_supervisor', false);
    }

    /**
     * Regression: the frontend sends boolean query params as the literal
     * string "true"/"false" (URLSearchParams stringification), which
     * Laravel's `boolean` validation rule rejects by default (it only
     * accepts true/false/0/1/'0'/'1' via strict comparison) - caught via
     * live browser testing, not by a JSON-body-only test.
     */
    public function test_supervisor_pool_queue_accepts_the_is_supervisor_query_string_flag(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $supervisor = $this->makeEmployee('active');
        $regular = $this->makeEmployee('active');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$supervisor->id}/supervisor", ['is_supervisor' => true])->assertOk();

        $response = $this->withToken($token)->getJson('/api/v1/admin/hr/employees?is_supervisor=true')->assertOk();
        $ids = array_column($response->json('data'), 'id');

        $this->assertContains($supervisor->id, $ids);
        $this->assertNotContains($regular->id, $ids);
    }

    public function test_former_employees_queue_only_shows_inactive_employees_with_separation_details(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $active = $this->makeEmployee('active');
        $former = $this->makeEmployee('active');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$former->id}/separate", [
            'reason' => 'other',
            'separation_date' => now()->toDateString(),
            'notes' => 'Left the country.',
        ])->assertOk();

        $response = $this->withToken($token)->getJson('/api/v1/admin/hr/employees?status=inactive')->assertOk();
        $ids = array_column($response->json('data'), 'id');

        $this->assertContains($former->id, $ids);
        $this->assertNotContains($active->id, $ids);

        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertSame('other', $byId[$former->id]['latest_separation']['reason']);
    }
}
