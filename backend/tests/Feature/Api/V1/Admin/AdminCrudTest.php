<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\AdminStatus;
use App\Models\Admin;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_an_admin(): void
    {
        $actor = Admin::factory()->superAdmin()->create();

        $response = $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/admin/admins', [
                'full_name' => 'Sales Admin',
                'email' => 'Sales.Admin@Example.com',
                'phone' => '+252610000111',
                'password' => 'password123',
                'role' => AdminRole::Sales->value,
                'status' => AdminStatus::Active->value,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Admin created successfully.')
            ->assertJsonPath('data.full_name', 'Sales Admin')
            ->assertJsonPath('data.email', 'sales.admin@example.com')
            ->assertJsonPath('data.phone', '+252610000111')
            ->assertJsonPath('data.role', AdminRole::Sales->value)
            ->assertJsonPath('data.status', AdminStatus::Active->value)
            ->assertJsonMissingPath('data.password');

        $created = Admin::query()->where('email', 'sales.admin@example.com')->first();

        $this->assertNotNull($created);
        $this->assertTrue(Hash::check('password123', $created->password));
    }

    public function test_super_admin_can_update_an_admin(): void
    {
        $actor = Admin::factory()->superAdmin()->create();
        $target = Admin::factory()->create([
            'role' => AdminRole::Manager,
            'status' => AdminStatus::Active,
        ]);

        $response = $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->putJson("/api/v1/admin/admins/{$target->id}", [
                'full_name' => 'Updated Manager',
                'email' => 'updated.manager@example.com',
                'phone' => '+252610000222',
                'role' => AdminRole::Inventory->value,
                'status' => AdminStatus::Inactive->value,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Admin updated successfully.')
            ->assertJsonPath('data.full_name', 'Updated Manager')
            ->assertJsonPath('data.email', 'updated.manager@example.com')
            ->assertJsonPath('data.phone', '+252610000222')
            ->assertJsonPath('data.role', AdminRole::Inventory->value)
            ->assertJsonPath('data.status', AdminStatus::Inactive->value);

        $this->assertDatabaseHas('admins', [
            'id' => $target->id,
            'full_name' => 'Updated Manager',
            'email' => 'updated.manager@example.com',
            'role' => AdminRole::Inventory->value,
            'status' => AdminStatus::Inactive->value,
        ]);
    }

    public function test_super_admin_can_soft_delete_an_admin(): void
    {
        $actor = Admin::factory()->superAdmin()->create();
        $target = Admin::factory()->create();

        $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->deleteJson("/api/v1/admin/admins/{$target->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Admin deleted successfully.');

        $this->assertSoftDeleted('admins', [
            'id' => $target->id,
        ]);
    }

    public function test_super_admin_cannot_delete_themselves(): void
    {
        $actor = Admin::factory()->superAdmin()->create();

        $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->deleteJson("/api/v1/admin/admins/{$actor->id}")
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'message' => 'You cannot delete your own admin account.',
                'error_code' => 'SELF_DELETE_NOT_ALLOWED',
            ]);

        $this->assertDatabaseHas('admins', [
            'id' => $actor->id,
            'deleted_at' => null,
        ]);
    }

    public function test_authenticated_admin_can_list_admins_newest_first(): void
    {
        $actor = Admin::factory()->create();
        $this->assignDirectPermissions($actor, [
            AdminPermission::AdminsManage,
        ]);
        $older = Admin::factory()->create([
            'full_name' => 'Older Admin',
        ]);
        $newer = Admin::factory()->create([
            'full_name' => 'Newer Admin',
        ]);

        $older->forceFill(['created_at' => now()->subDays(2)])->save();
        $actor->forceFill(['created_at' => now()->subDay()])->save();
        $newer->forceFill(['created_at' => now()])->save();

        $response = $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/admins');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Admins retrieved successfully.')
            ->assertJsonPath('data.pagination.total', 3);

        $ids = collect($response->json('data.items'))->pluck('id')->all();

        $this->assertSame($newer->id, $ids[0]);
        $this->assertContains($older->id, $ids);
        $this->assertContains($actor->id, $ids);
    }

    public function test_admin_detail_includes_effective_permissions(): void
    {
        $actor = Admin::factory()->create();
        $this->assignDirectPermissions($actor, [
            AdminPermission::AdminsManage,
        ]);
        $target = Admin::factory()->create([
            'role' => AdminRole::Sales,
        ]);

        $this->assignRolePermissions(AdminRole::Sales, [
            AdminPermission::ProductsCreate,
        ]);
        $this->assignDirectPermissions($target, [
            AdminPermission::SuppliersManage,
        ]);

        $response = $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->getJson("/api/v1/admin/admins/{$target->id}");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Admin retrieved successfully.')
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.email', $target->email)
            ->assertJsonCount(3, 'data.effective_permissions')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $keys = collect($response->json('data.effective_permissions'))->pluck('key')->sort()->values()->all();

        $this->assertSame(
            [
                AdminPermission::DashboardView->value,
                AdminPermission::ProductsCreate->value,
                AdminPermission::SuppliersManage->value,
            ],
            $keys,
        );
    }

    public function test_admin_list_supports_role_status_and_search_filters(): void
    {
        $actor = Admin::factory()->superAdmin()->create([
            'full_name' => 'Root Admin',
            'email' => 'root@example.com',
            'phone' => '+252610000001',
        ]);

        Admin::factory()->create([
            'full_name' => 'Sales One',
            'email' => 'sales.one@example.com',
            'phone' => '+252610000002',
            'role' => AdminRole::Sales,
            'status' => AdminStatus::Active,
        ]);

        Admin::factory()->inactive()->create([
            'full_name' => 'Sales Two',
            'email' => 'sales.two@example.com',
            'phone' => '+252610000003',
            'role' => AdminRole::Sales,
        ]);

        Admin::factory()->create([
            'full_name' => 'Inventory Lead',
            'email' => 'inventory@example.com',
            'phone' => '+252610000004',
            'role' => AdminRole::Inventory,
            'status' => AdminStatus::Active,
        ]);

        $token = $actor->createToken('admin-panel')->plainTextToken;

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/admins?role=sales')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/admins?status=inactive')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.full_name', 'Sales Two');

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/admins?search=inventory')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.email', 'inventory@example.com');

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/admins?search=%2B252610000002')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.phone', '+252610000002');

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/admins?role=not_a_role')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_create_and_update_admin_validation(): void
    {
        $actor = Admin::factory()->superAdmin()->create();
        $existing = Admin::factory()->create([
            'email' => 'taken@example.com',
            'phone' => '+252610000999',
        ]);
        $token = $actor->createToken('admin-panel')->plainTextToken;

        $this
            ->withToken($token)
            ->postJson('/api/v1/admin/admins', [])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this
            ->withToken($token)
            ->postJson('/api/v1/admin/admins', [
                'full_name' => 'Duplicate',
                'email' => 'taken@example.com',
                'phone' => '+252610000888',
                'password' => 'password123',
                'role' => AdminRole::Sales->value,
                'status' => AdminStatus::Active->value,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this
            ->withToken($token)
            ->putJson("/api/v1/admin/admins/{$existing->id}", [
                'email' => 'not-an-email',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_non_super_admin_cannot_mutate_admins(): void
    {
        $actor = Admin::factory()->create([
            'role' => AdminRole::Manager,
        ]);
        $this->assignDirectPermissions($actor, [
            AdminPermission::AdminsManage,
        ]);
        $target = Admin::factory()->create();
        $token = $actor->createToken('admin-panel')->plainTextToken;

        $this
            ->withToken($token)
            ->postJson('/api/v1/admin/admins', [
                'full_name' => 'Blocked',
                'email' => 'blocked@example.com',
                'phone' => '+252610000777',
                'password' => 'password123',
                'role' => AdminRole::Sales->value,
                'status' => AdminStatus::Active->value,
            ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this
            ->withToken($token)
            ->putJson("/api/v1/admin/admins/{$target->id}", [
                'full_name' => 'Nope',
            ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this
            ->withToken($token)
            ->deleteJson("/api/v1/admin/admins/{$target->id}")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_unauthenticated_and_customer_tokens_are_rejected(): void
    {
        $target = Admin::factory()->create();

        $this->getJson('/api/v1/admin/admins')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->getJson("/api/v1/admin/admins/{$target->id}")
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->postJson('/api/v1/admin/admins', [])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $user = User::factory()->create();
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/admins')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this
            ->withToken($token)
            ->postJson('/api/v1/admin/admins', [
                'full_name' => 'Customer',
                'email' => 'customer.admin@example.com',
                'phone' => '+252610000666',
                'password' => 'password123',
                'role' => AdminRole::Sales->value,
                'status' => AdminStatus::Active->value,
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    /**
     * @param  list<AdminPermission>  $permissions
     */
    private function assignRolePermissions(AdminRole $role, array $permissions): void
    {
        $now = now();

        DB::table('admin_role_permissions')->insert(
            collect($permissions)
                ->map(fn (AdminPermission $permission): array => [
                    'role' => $role->value,
                    'permission_id' => Permission::query()->where('key', $permission->value)->value('id'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all(),
        );
    }

    /**
     * @param  list<AdminPermission>  $permissions
     */
    private function assignDirectPermissions(Admin $admin, array $permissions): void
    {
        $now = now();

        DB::table('admin_permissions')->insert(
            collect($permissions)
                ->map(fn (AdminPermission $permission): array => [
                    'admin_id' => $admin->id,
                    'permission_id' => Permission::query()->where('key', $permission->value)->value('id'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all(),
        );
    }
}
