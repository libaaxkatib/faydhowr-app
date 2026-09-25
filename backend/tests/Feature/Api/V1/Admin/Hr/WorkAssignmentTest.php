<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\Permission;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Defaults to 'active' since most of this file's tests are about
     * assignment mechanics, not the recruitment pipeline itself — pass an
     * explicit status to exercise the pipeline-gating rule.
     */
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

    public function test_only_active_employees_may_receive_a_work_assignment(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();

        $payload = [
            'work_location_id' => $office->id,
            'start_date' => now()->toDateString(),
            'salary_amount' => 100,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
        ];

        foreach (['applicant', 'recruitment', 'practical', 'waiting', 'approved'] as $status) {
            $employee = $this->makeEmployee($status);

            $this->withToken($token)
                ->postJson("/api/v1/admin/hr/employees/{$employee->id}/work-assignments", $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors('employee_status');
        }

        $activeEmployee = $this->makeEmployee('active');
        $this->withToken($token)
            ->postJson("/api/v1/admin/hr/employees/{$activeEmployee->id}/work-assignments", $payload)
            ->assertStatus(201);
    }

    public function test_office_row_is_seeded_with_default_capacity(): void
    {
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();

        $this->assertSame('Fayadhowr Office', $office->name);
        $this->assertSame(15, $office->capacity);
        $this->assertNull($office->client_company_id);
    }

    public function test_client_company_location_requires_client_company_id(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $response = $this
            ->withToken($admin->createToken('t')->plainTextToken)
            ->postJson('/api/v1/admin/hr/work-locations', [
                'location_type' => 'client',
                'name' => 'Orphan Location',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('client_company_id');
    }

    public function test_office_location_rejects_client_company_id(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $company = ClientCompany::query()->create(['name' => 'ABC Co', 'status' => 'active']);

        $response = $this
            ->withToken($admin->createToken('t')->plainTextToken)
            ->postJson('/api/v1/admin/hr/work-locations', [
                'location_type' => 'office',
                'client_company_id' => $company->id,
                'name' => 'Bad Office',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('client_company_id');
    }

    public function test_employee_can_hold_two_concurrent_active_assignments_at_different_locations(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee();
        $company = ClientCompany::query()->create(['name' => 'ABC Co', 'status' => 'active']);
        $clientLocation = WorkLocation::query()->create([
            'client_company_id' => $company->id,
            'location_type' => 'client',
            'name' => 'ABC Center',
            'status' => 'active',
        ]);
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/work-assignments", [
            'work_location_id' => $clientLocation->id,
            'start_date' => now()->toDateString(),
            'salary_amount' => 150,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
        ])->assertStatus(201);

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/work-assignments", [
            'work_location_id' => $office->id,
            'start_date' => now()->toDateString(),
            'salary_amount' => 50,
            'salary_currency' => 'USD',
            'salary_frequency' => 'weekly',
        ])->assertStatus(201);

        $response = $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employee->id}");

        $response->assertOk();
        $this->assertCount(2, $response->json('data.active_work_assignments'));
    }

    public function test_employee_cannot_receive_two_active_assignments_at_the_same_location(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee();
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/work-assignments", [
            'work_location_id' => $office->id,
            'start_date' => now()->toDateString(),
            'salary_amount' => 100,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
        ])->assertStatus(201);

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/work-assignments", [
            'work_location_id' => $office->id,
            'start_date' => now()->toDateString(),
            'salary_amount' => 100,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
        ])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_ALREADY_ASSIGNED_AT_LOCATION');
    }

    public function test_ending_an_assignment_preserves_history_instead_of_deleting_it(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee();
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();

        $created = $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/work-assignments", [
            'work_location_id' => $office->id,
            'start_date' => now()->toDateString(),
            'salary_amount' => 100,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
        ])->assertStatus(201)->json('data.id');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/work-assignments/{$created}/end", [
            'end_date' => now()->addMonth()->toDateString(),
        ])->assertOk()->assertJsonPath('data.status', 'ended');

        $response = $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employee->id}");

        $this->assertCount(0, $response->json('data.active_work_assignments'));
        $this->assertCount(1, $response->json('data.work_assignments'));
        $this->assertSame('ended', $response->json('data.work_assignments.0.status'));
    }

    public function test_capacity_is_enforced_and_only_super_admin_may_override_it(): void
    {
        $superAdmin = Admin::factory()->superAdmin()->create();
        $manager = Admin::factory()->create(['role' => AdminRole::HrManager]);
        DB::table('admin_permissions')->insert([
            'admin_id' => $manager->id,
            'permission_id' => Permission::query()->where('key', AdminPermission::HrManage->value)->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $superToken = $superAdmin->createToken('t')->plainTextToken;
        $managerToken = $manager->createToken('t')->plainTextToken;

        $company = ClientCompany::query()->create(['name' => 'Capped Co', 'status' => 'active']);
        $location = WorkLocation::query()->create([
            'client_company_id' => $company->id,
            'location_type' => 'client',
            'name' => 'Capped Center',
            'capacity' => 1,
            'status' => 'active',
        ]);

        $first = $this->makeEmployee();
        $this->withToken($superToken)->postJson("/api/v1/admin/hr/employees/{$first->id}/work-assignments", [
            'work_location_id' => $location->id,
            'start_date' => now()->toDateString(),
            'salary_amount' => 120,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
        ])->assertStatus(201);

        $second = $this->makeEmployee();

        // Manager cannot exceed capacity even when passing the override flag.
        $this->app['auth']->forgetGuards();
        $this->withToken($managerToken)->postJson("/api/v1/admin/hr/employees/{$second->id}/work-assignments", [
            'work_location_id' => $location->id,
            'start_date' => now()->toDateString(),
            'salary_amount' => 120,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
            'override_capacity' => true,
        ])->assertStatus(422)->assertJsonPath('error_code', 'WORK_LOCATION_CAPACITY_EXCEEDED');

        // Super admin without the flag is also blocked.
        $this->app['auth']->forgetGuards();
        $this->withToken($superToken)->postJson("/api/v1/admin/hr/employees/{$second->id}/work-assignments", [
            'work_location_id' => $location->id,
            'start_date' => now()->toDateString(),
            'salary_amount' => 120,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
        ])->assertStatus(422)->assertJsonPath('error_code', 'WORK_LOCATION_CAPACITY_EXCEEDED');

        // Super admin with the flag succeeds.
        $this->withToken($superToken)->postJson("/api/v1/admin/hr/employees/{$second->id}/work-assignments", [
            'work_location_id' => $location->id,
            'start_date' => now()->toDateString(),
            'salary_amount' => 120,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
            'override_capacity' => true,
        ])->assertStatus(201);
    }

    public function test_deleting_a_referenced_client_company_or_location_is_blocked(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $company = ClientCompany::query()->create(['name' => 'Referenced Co', 'status' => 'active']);
        $location = WorkLocation::query()->create([
            'client_company_id' => $company->id,
            'location_type' => 'client',
            'name' => 'Referenced Center',
            'status' => 'active',
        ]);

        $this->withToken($token)->deleteJson("/api/v1/admin/hr/client-companies/{$company->id}")
            ->assertStatus(422)->assertJsonPath('error_code', 'CLIENT_COMPANY_HAS_WORK_LOCATIONS');

        $employee = $this->makeEmployee();
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/work-assignments", [
            'work_location_id' => $location->id,
            'start_date' => now()->toDateString(),
            'salary_amount' => 100,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
        ])->assertStatus(201);

        $this->withToken($token)->deleteJson("/api/v1/admin/hr/work-locations/{$location->id}")
            ->assertStatus(422)->assertJsonPath('error_code', 'WORK_LOCATION_HAS_ASSIGNMENTS');
    }
}
