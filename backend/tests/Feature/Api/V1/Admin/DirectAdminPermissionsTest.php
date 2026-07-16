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

class DirectAdminPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_can_list_direct_and_effective_permissions(): void
    {
        $actor = Admin::factory()->create();
        $this->assignDirectPermissions($actor, [
            AdminPermission::RolesManage,
        ]);
        $target = Admin::factory()->create([
            'role' => AdminRole::Manager,
        ]);

        $this->assignRolePermissions(AdminRole::Manager, [
            AdminPermission::ProductsCreate,
            AdminPermission::SuppliersManage,
        ]);

        $this->assignDirectPermissions($target, [
            AdminPermission::GoodsReceiptsManage,
            AdminPermission::ProductsCreate,
        ]);

        $response = $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->getJson("/api/v1/admin/admins/{$target->id}/permissions");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Admin permissions retrieved successfully.')
            ->assertJsonPath('data.role', AdminRole::Manager->value)
            ->assertJsonCount(2, 'data.role_permissions')
            ->assertJsonCount(2, 'data.direct_permissions')
            ->assertJsonCount(3, 'data.effective_permissions');

        $this->assertSame(
            [
                AdminPermission::ProductsCreate->value,
                AdminPermission::SuppliersManage->value,
            ],
            collect($response->json('data.role_permissions'))->pluck('key')->sort()->values()->all(),
        );

        $this->assertSame(
            [
                AdminPermission::GoodsReceiptsManage->value,
                AdminPermission::ProductsCreate->value,
            ],
            collect($response->json('data.direct_permissions'))->pluck('key')->sort()->values()->all(),
        );

        $this->assertSame(
            [
                AdminPermission::GoodsReceiptsManage->value,
                AdminPermission::ProductsCreate->value,
                AdminPermission::SuppliersManage->value,
            ],
            collect($response->json('data.effective_permissions'))->pluck('key')->sort()->values()->all(),
        );
    }

    public function test_super_admin_can_update_direct_permissions_atomically(): void
    {
        $actor = Admin::factory()->superAdmin()->create();
        $target = Admin::factory()->create([
            'role' => AdminRole::Sales,
        ]);

        $this->assignRolePermissions(AdminRole::Sales, [
            AdminPermission::ProductsUpdate,
        ]);

        $response = $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->putJson("/api/v1/admin/admins/{$target->id}/permissions", [
                'permissions' => [
                    AdminPermission::ProductsCreate->value,
                    AdminPermission::PurchaseOrdersManage->value,
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Admin permissions updated successfully.')
            ->assertJsonPath('data.role', AdminRole::Sales->value)
            ->assertJsonCount(1, 'data.role_permissions')
            ->assertJsonCount(2, 'data.direct_permissions')
            ->assertJsonCount(3, 'data.effective_permissions');

        $this->assertSame(
            2,
            DB::table('admin_permissions')->where('admin_id', $target->id)->count(),
        );

        $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->putJson("/api/v1/admin/admins/{$target->id}/permissions", [
                'permissions' => [AdminPermission::SuppliersManage->value],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.direct_permissions')
            ->assertJsonPath('data.direct_permissions.0.key', AdminPermission::SuppliersManage->value);

        $this->assertSame(
            1,
            DB::table('admin_permissions')->where('admin_id', $target->id)->count(),
        );

        $this->assertSame(
            1,
            DB::table('admin_role_permissions')->where('role', AdminRole::Sales->value)->count(),
        );
    }

    public function test_effective_permissions_union_role_and_direct_without_removing_role_grants(): void
    {
        $actor = Admin::factory()->superAdmin()->create();
        $target = Admin::factory()->create([
            'role' => AdminRole::Inventory,
        ]);

        $this->assignRolePermissions(AdminRole::Inventory, [
            AdminPermission::SuppliersManage,
            AdminPermission::PurchaseOrdersManage,
        ]);

        $response = $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->putJson("/api/v1/admin/admins/{$target->id}/permissions", [
                'permissions' => [AdminPermission::GoodsReceiptsManage->value],
            ]);

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data.role_permissions')
            ->assertJsonCount(1, 'data.direct_permissions')
            ->assertJsonCount(3, 'data.effective_permissions');

        $effectiveKeys = collect($response->json('data.effective_permissions'))->pluck('key')->all();

        $this->assertContains(AdminPermission::SuppliersManage->value, $effectiveKeys);
        $this->assertContains(AdminPermission::PurchaseOrdersManage->value, $effectiveKeys);
        $this->assertContains(AdminPermission::GoodsReceiptsManage->value, $effectiveKeys);
    }

    public function test_super_admin_direct_permissions_cannot_be_persisted(): void
    {
        $actor = Admin::factory()->superAdmin()->create();
        $target = Admin::factory()->superAdmin()->create();

        $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->putJson("/api/v1/admin/admins/{$target->id}/permissions", [
                'permissions' => [AdminPermission::RolesManage->value],
            ])
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'message' => 'Super Admin permissions are implicit and cannot be persisted.',
                'error_code' => 'SUPER_ADMIN_PERMISSIONS_IMMUTABLE',
            ]);

        $this->assertDatabaseCount('admin_permissions', 0);

        $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->getJson("/api/v1/admin/admins/{$target->id}/permissions")
            ->assertOk()
            ->assertJsonPath('data.role', AdminRole::SuperAdmin->value)
            ->assertJsonCount(0, 'data.role_permissions')
            ->assertJsonCount(0, 'data.direct_permissions')
            ->assertJsonCount(count(AdminPermission::cases()), 'data.effective_permissions');
    }

    public function test_update_direct_permissions_validates_payload(): void
    {
        $actor = Admin::factory()->superAdmin()->create();
        $target = Admin::factory()->create();
        $token = $actor->createToken('admin-panel')->plainTextToken;

        $this
            ->withToken($token)
            ->putJson("/api/v1/admin/admins/{$target->id}/permissions", [])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this
            ->withToken($token)
            ->putJson("/api/v1/admin/admins/{$target->id}/permissions", [
                'permissions' => ['not.a.permission'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/admins/999999/permissions')
            ->assertNotFound();
    }

    public function test_non_super_admin_cannot_update_direct_permissions(): void
    {
        $actor = Admin::factory()->create([
            'role' => AdminRole::Manager,
        ]);
        $this->assignDirectPermissions($actor, [
            AdminPermission::RolesManage,
        ]);
        $target = Admin::factory()->create([
            'role' => AdminRole::Sales,
        ]);

        $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->putJson("/api/v1/admin/admins/{$target->id}/permissions", [
                'permissions' => [AdminPermission::ProductsCreate->value],
            ])
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => 'Only Super Admin may update direct admin permissions.',
                'error_code' => 'FORBIDDEN',
            ]);

        $this->assertDatabaseMissing('admin_permissions', [
            'admin_id' => $target->id,
        ]);
    }

    public function test_unauthenticated_access_to_direct_admin_permissions_is_rejected(): void
    {
        $target = Admin::factory()->create();

        $this->getJson("/api/v1/admin/admins/{$target->id}/permissions")
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->putJson("/api/v1/admin/admins/{$target->id}/permissions", [
            'permissions' => [AdminPermission::ProductsCreate->value],
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_customer_token_cannot_access_direct_admin_permissions(): void
    {
        $target = Admin::factory()->create();
        $user = User::factory()->create();
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $this
            ->withToken($token)
            ->getJson("/api/v1/admin/admins/{$target->id}/permissions")
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this
            ->withToken($token)
            ->putJson("/api/v1/admin/admins/{$target->id}/permissions", [
                'permissions' => [AdminPermission::ProductsCreate->value],
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
