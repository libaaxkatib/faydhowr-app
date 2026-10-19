<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeWorkAssignment;
use App\Models\Permission;
use App\Models\Position;
use App\Models\WorkforceRequest;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P0.1 - Positions and Departments that anything still points at are never deleted.
 *
 * The foreign keys behind these records are ON DELETE SET NULL, so before this guard a delete went through and silently
 * blanked the reference on every employee, assignment and request. These tests pin the refusal, the unchanged
 * references, the untouched audit log, the clear message and the unchanged behaviour for records nobody uses.
 */
class PositionDepartmentDeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminToken(): string
    {
        return Admin::factory()->superAdmin()->create()->createToken('t')->plainTextToken;
    }

    private function tokenWithOnly(AdminPermission $permission): string
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Manager]);

        DB::table('admin_permissions')->insert([
            'admin_id' => $admin->id,
            'permission_id' => Permission::query()->where('key', $permission->value)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $admin->createToken('t')->plainTextToken;
    }

    /** Switches the caller: Sanctum caches the authenticated admin per request cycle, so the guards are reset first. */
    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function makeEmployee(array $attributes = []): Employee
    {
        return Employee::query()->create($attributes + [
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name(),
            'phone' => fake()->unique()->e164PhoneNumber(),
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'status' => 'active',
            'application_date' => now()->toDateString(),
        ]);
    }

    private function makeAssignment(Employee $employee, ?int $positionId, string $status = 'active'): EmployeeWorkAssignment
    {
        return EmployeeWorkAssignment::query()->create([
            'employee_id' => $employee->id,
            'work_location_id' => WorkLocation::query()->where('location_type', 'office')->firstOrFail()->id,
            'position_id' => $positionId,
            'start_date' => now()->toDateString(),
            'salary_amount' => 100,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
            'status' => $status,
        ]);
    }

    private function makeRequest(?int $positionId): WorkforceRequest
    {
        return WorkforceRequest::query()->create([
            'work_location_id' => WorkLocation::query()->where('location_type', 'office')->firstOrFail()->id,
            'position_id' => $positionId,
            'quantity_needed' => 1,
            'status' => 'open',
            'requested_date' => now()->toDateString(),
        ]);
    }

    private function deleteAuditCount(): int
    {
        return AuditLog::query()->where('action', 'delete')->count();
    }

    // ---------------------------------------------------------------- positions

    public function test_an_unused_position_is_still_deleted_and_audited(): void
    {
        $position = Position::query()->create(['name' => 'Spare Position']);

        $this->withToken($this->superAdminToken())->deleteJson("/api/v1/admin/hr/positions/{$position->id}")
            ->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseMissing('positions', ['id' => $position->id]);
        $this->assertSame(1, $this->deleteAuditCount());
    }

    public function test_a_position_used_by_an_employee_is_refused_and_the_reference_is_kept(): void
    {
        $position = Position::query()->create(['name' => 'Cashier']);
        $employee = $this->makeEmployee(['position_id' => $position->id]);

        $this->withToken($this->superAdminToken())->deleteJson("/api/v1/admin/hr/positions/{$position->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'POSITION_IN_USE')
            ->assertJsonPath('message', 'This position cannot be deleted because it is still used by 1 employee. Move or remove those records first.');

        $this->assertDatabaseHas('positions', ['id' => $position->id]);
        $this->assertSame($position->id, $employee->fresh()->position_id);
        $this->assertSame(0, $this->deleteAuditCount());
    }

    public function test_an_archived_employee_still_protects_the_position(): void
    {
        $position = Position::query()->create(['name' => 'Cook']);
        $employee = $this->makeEmployee(['position_id' => $position->id]);
        $employee->delete(); // soft delete = archived

        $this->withToken($this->superAdminToken())->deleteJson("/api/v1/admin/hr/positions/{$position->id}")
            ->assertStatus(422)->assertJsonPath('error_code', 'POSITION_IN_USE')
            ->assertJsonPath('message', 'This position cannot be deleted because it is still used by 1 employee (1 archived). Move or remove those records first.');

        $this->assertSame($position->id, Employee::withTrashed()->findOrFail($employee->id)->position_id);
    }

    public function test_assignments_including_ended_and_cancelled_ones_protect_the_position(): void
    {
        $position = Position::query()->create(['name' => 'Waiter']);
        $someone = $this->makeEmployee();

        $ended = $this->makeAssignment($someone, $position->id, 'ended');
        $cancelled = $this->makeAssignment($someone, $position->id, 'cancelled');

        $this->withToken($this->superAdminToken())->deleteJson("/api/v1/admin/hr/positions/{$position->id}")
            ->assertStatus(422)->assertJsonPath('error_code', 'POSITION_IN_USE')
            ->assertJsonPath('message', 'This position cannot be deleted because it is still used by 2 work assignments. Move or remove those records first.');

        $this->assertSame($position->id, $ended->fresh()->position_id);
        $this->assertSame($position->id, $cancelled->fresh()->position_id);
    }

    public function test_a_workforce_request_protects_the_position(): void
    {
        $position = Position::query()->create(['name' => 'Barista']);
        $request = $this->makeRequest($position->id);

        $this->withToken($this->superAdminToken())->deleteJson("/api/v1/admin/hr/positions/{$position->id}")
            ->assertStatus(422)->assertJsonPath('error_code', 'POSITION_IN_USE')
            ->assertJsonPath('message', 'This position cannot be deleted because it is still used by 1 workforce request. Move or remove those records first.');

        $this->assertSame($position->id, $request->fresh()->position_id);
    }

    public function test_the_message_lists_every_kind_of_use_and_nothing_is_changed(): void
    {
        $position = Position::query()->create(['name' => 'Supervisor Assistant']);
        $a = $this->makeEmployee(['position_id' => $position->id]);
        $b = $this->makeEmployee(['position_id' => $position->id]);
        $assignment = $this->makeAssignment($a, $position->id);
        $request = $this->makeRequest($position->id);

        $this->withToken($this->superAdminToken())->deleteJson("/api/v1/admin/hr/positions/{$position->id}")
            ->assertStatus(422)->assertJsonPath('error_code', 'POSITION_IN_USE')
            ->assertJsonPath('message', 'This position cannot be deleted because it is still used by 2 employees, 1 work assignment and 1 workforce request. Move or remove those records first.');

        $this->assertDatabaseHas('positions', ['id' => $position->id]);
        $this->assertSame($position->id, $a->fresh()->position_id);
        $this->assertSame($position->id, $b->fresh()->position_id);
        $this->assertSame($position->id, $assignment->fresh()->position_id);
        $this->assertSame($position->id, $request->fresh()->position_id);
        $this->assertSame(0, $this->deleteAuditCount());
    }

    public function test_a_position_can_be_deleted_once_nothing_uses_it_any_more(): void
    {
        $position = Position::query()->create(['name' => 'Temporary Role']);
        $employee = $this->makeEmployee(['position_id' => $position->id]);
        $token = $this->superAdminToken();

        $this->withToken($token)->deleteJson("/api/v1/admin/hr/positions/{$position->id}")->assertStatus(422);

        $employee->update(['position_id' => null]); // the explicit, deliberate move the message asks for

        $this->withToken($token)->deleteJson("/api/v1/admin/hr/positions/{$position->id}")->assertOk();
        $this->assertDatabaseMissing('positions', ['id' => $position->id]);
    }

    public function test_deleting_a_position_requires_hr_manage_and_an_existing_position(): void
    {
        $position = Position::query()->create(['name' => 'Guarded']);

        $this->deleteJson("/api/v1/admin/hr/positions/{$position->id}")->assertStatus(401);

        $this->asToken($this->tokenWithOnly(AdminPermission::HrView))->deleteJson("/api/v1/admin/hr/positions/{$position->id}")
            ->assertStatus(403);

        $this->asToken($this->superAdminToken())->deleteJson('/api/v1/admin/hr/positions/999999')->assertStatus(404);

        $this->assertDatabaseHas('positions', ['id' => $position->id]);
    }

    // -------------------------------------------------------------- departments

    public function test_an_unused_department_is_still_deleted_and_audited(): void
    {
        $department = Department::query()->create(['name' => 'Spare Department']);

        $this->withToken($this->superAdminToken())->deleteJson("/api/v1/admin/hr/departments/{$department->id}")
            ->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
        $this->assertSame(1, $this->deleteAuditCount());
    }

    public function test_a_department_with_positions_is_refused_and_the_positions_keep_it(): void
    {
        $department = Department::query()->create(['name' => 'Operations']);
        $one = Position::query()->create(['name' => 'Coordinator', 'department_id' => $department->id]);
        $two = Position::query()->create(['name' => 'Dispatcher', 'department_id' => $department->id]);

        $this->withToken($this->superAdminToken())->deleteJson("/api/v1/admin/hr/departments/{$department->id}")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'DEPARTMENT_IN_USE')
            ->assertJsonPath('message', 'This department cannot be deleted because it is still used by 2 positions. Move or remove those records first.');

        $this->assertDatabaseHas('departments', ['id' => $department->id]);
        $this->assertSame($department->id, $one->fresh()->department_id);
        $this->assertSame($department->id, $two->fresh()->department_id);
        $this->assertSame(0, $this->deleteAuditCount());
    }

    public function test_employees_including_archived_ones_protect_the_department(): void
    {
        $department = Department::query()->create(['name' => 'Kitchen']);
        $active = $this->makeEmployee(['department_id' => $department->id]);
        $archived = $this->makeEmployee(['department_id' => $department->id]);
        $archived->delete();

        $this->withToken($this->superAdminToken())->deleteJson("/api/v1/admin/hr/departments/{$department->id}")
            ->assertStatus(422)->assertJsonPath('error_code', 'DEPARTMENT_IN_USE')
            ->assertJsonPath('message', 'This department cannot be deleted because it is still used by 2 employees (1 archived). Move or remove those records first.');

        $this->assertSame($department->id, $active->fresh()->department_id);
        $this->assertSame($department->id, Employee::withTrashed()->findOrFail($archived->id)->department_id);
    }

    public function test_positions_and_employees_are_both_named_in_the_department_message(): void
    {
        $department = Department::query()->create(['name' => 'Front Office']);
        Position::query()->create(['name' => 'Receptionist', 'department_id' => $department->id]);
        $this->makeEmployee(['department_id' => $department->id]);

        $this->withToken($this->superAdminToken())->deleteJson("/api/v1/admin/hr/departments/{$department->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This department cannot be deleted because it is still used by 1 position and 1 employee. Move or remove those records first.');
    }

    public function test_a_department_can_be_deleted_once_it_is_empty_and_requires_hr_manage(): void
    {
        $department = Department::query()->create(['name' => 'Emptying']);
        $position = Position::query()->create(['name' => 'Clerk', 'department_id' => $department->id]);

        $this->deleteJson("/api/v1/admin/hr/departments/{$department->id}")->assertStatus(401);
        $this->asToken($this->tokenWithOnly(AdminPermission::HrView))->deleteJson("/api/v1/admin/hr/departments/{$department->id}")
            ->assertStatus(403);

        $token = $this->superAdminToken();
        $this->asToken($token)->deleteJson("/api/v1/admin/hr/departments/{$department->id}")->assertStatus(422);

        $position->update(['department_id' => null]);

        $this->withToken($token)->deleteJson("/api/v1/admin/hr/departments/{$department->id}")->assertOk();
        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
        $this->assertDatabaseHas('positions', ['id' => $position->id]); // the position itself is untouched
    }

    public function test_a_refused_delete_does_not_touch_any_unrelated_record(): void
    {
        $position = Position::query()->create(['name' => 'Anchor']);
        $other = Position::query()->create(['name' => 'Bystander']);
        $employee = $this->makeEmployee(['position_id' => $position->id]);
        $before = [Position::query()->count(), Employee::query()->count()];

        $this->withToken($this->superAdminToken())->deleteJson("/api/v1/admin/hr/positions/{$position->id}")->assertStatus(422);

        $this->assertSame($before, [Position::query()->count(), Employee::query()->count()]);
        $this->assertDatabaseHas('positions', ['id' => $other->id]);
        $this->assertSame($position->id, $employee->fresh()->position_id);
    }
}
