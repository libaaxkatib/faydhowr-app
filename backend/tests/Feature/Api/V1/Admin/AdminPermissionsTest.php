<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_can_list_permissions(): void
    {
        $admin = Admin::factory()->create();
        $this->grantPermissions($admin, [AdminPermission::RolesManage]);

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/permissions');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Permissions retrieved successfully.')
            ->assertJsonCount(count(AdminPermission::cases()), 'data');

        $response->assertJsonFragment([
            'key' => AdminPermission::ProductsCreate->value,
            'name' => AdminPermission::ProductsCreate->label(),
            'group' => 'Products',
        ]);

        $response->assertJsonFragment([
            'key' => AdminPermission::RolesManage->value,
            'name' => AdminPermission::RolesManage->label(),
            'group' => 'Admins',
        ]);

        $this->assertSame(
            Permission::query()->orderBy('group')->orderBy('key')->pluck('key')->all(),
            collect($response->json('data'))->pluck('key')->all(),
        );
    }

    public function test_super_admin_can_update_role_permissions(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $keys = [
            AdminPermission::ProductsCreate->value,
            AdminPermission::ProductsUpdate->value,
            AdminPermission::SuppliersManage->value,
        ];

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->putJson('/api/v1/admin/roles/manager/permissions', [
                'permissions' => $keys,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Role permissions updated successfully.')
            ->assertJsonPath('data.role', AdminRole::Manager->value)
            ->assertJsonCount(3, 'data.permissions');

        foreach ($keys as $key) {
            $response->assertJsonFragment(['key' => $key]);
        }

        $this->assertSame(
            3,
            DB::table('admin_role_permissions')
                ->where('role', AdminRole::Manager->value)
                ->count(),
        );

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->putJson('/api/v1/admin/roles/manager/permissions', [
                'permissions' => [AdminPermission::ProductsCreate->value],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.permissions');

        $this->assertSame(
            1,
            DB::table('admin_role_permissions')
                ->where('role', AdminRole::Manager->value)
                ->count(),
        );
    }

    public function test_super_admin_permissions_cannot_be_persisted(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->putJson('/api/v1/admin/roles/super_admin/permissions', [
                'permissions' => [AdminPermission::RolesManage->value],
            ])
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'message' => 'Super Admin permissions are implicit and cannot be persisted.',
                'error_code' => 'SUPER_ADMIN_PERMISSIONS_IMMUTABLE',
            ]);

        $this->assertDatabaseCount('admin_role_permissions', 0);
    }

    public function test_update_role_permissions_validates_payload(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this
            ->withToken($token)
            ->putJson('/api/v1/admin/roles/manager/permissions', [])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this
            ->withToken($token)
            ->putJson('/api/v1/admin/roles/manager/permissions', [
                'permissions' => ['not.a.permission'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this
            ->withToken($token)
            ->putJson('/api/v1/admin/roles/unknown_role/permissions', [
                'permissions' => [AdminPermission::ProductsCreate->value],
            ])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'ROLE_NOT_FOUND');
    }

    public function test_non_super_admin_cannot_update_role_permissions(): void
    {
        $admin = Admin::factory()->create([
            'role' => AdminRole::Manager,
        ]);
        $this->grantPermissions($admin, [AdminPermission::RolesManage]);

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->putJson('/api/v1/admin/roles/sales/permissions', [
                'permissions' => [AdminPermission::ProductsCreate->value],
            ])
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => 'Only Super Admin may update role permissions.',
                'error_code' => 'FORBIDDEN',
            ]);

        $this->assertDatabaseCount('admin_role_permissions', 0);
    }

    public function test_unauthenticated_access_to_permissions_endpoints_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/permissions')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->putJson('/api/v1/admin/roles/manager/permissions', [
            'permissions' => [AdminPermission::ProductsCreate->value],
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_customer_token_cannot_access_admin_permissions(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/permissions')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this
            ->withToken($token)
            ->putJson('/api/v1/admin/roles/manager/permissions', [
                'permissions' => [AdminPermission::ProductsCreate->value],
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    /**
     * @param  list<AdminPermission>  $permissions
     */
    private function grantPermissions(Admin $admin, array $permissions): void
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
