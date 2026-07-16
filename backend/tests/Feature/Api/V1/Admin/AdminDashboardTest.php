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

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_receives_super_admin_dashboard_sections(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Dashboard retrieved successfully.')
            ->assertJsonPath('data.dashboard_type', 'super_admin')
            ->assertJsonPath('data.role', AdminRole::SuperAdmin->value)
            ->assertJsonPath('data.visible_modules', [
                'dashboard',
                'admin_management',
                'roles_permissions',
                'customers',
                'services',
                'bookings',
                'quotations',
                'orders',
                'store',
                'inventory',
                'payments',
                'reports',
                'system_settings',
            ])
            ->assertJsonPath('data.visible_navigation.0.key', 'dashboard')
            ->assertJsonPath('data.visible_navigation.0.label', 'Dashboard')
            ->assertJsonPath('data.visible_navigation.12.key', 'system_settings')
            ->assertJsonMissingPath('data.charts');
    }

    public function test_manager_dashboard_shows_store_and_inventory_from_aligned_permissions(): void
    {
        $admin = Admin::factory()->create([
            'role' => AdminRole::Manager,
        ]);

        $this->assignRolePermissions(AdminRole::Manager, [
            AdminPermission::ProductsCreate,
            AdminPermission::ProductsUpdate,
            AdminPermission::ProductsDelete,
            AdminPermission::SuppliersManage,
            AdminPermission::PurchaseOrdersManage,
            AdminPermission::GoodsReceiptsManage,
        ]);

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('data.dashboard_type', 'operations')
            ->assertJsonPath('data.role', AdminRole::Manager->value);

        $modules = $response->json('data.visible_modules');

        $this->assertSame([
            'dashboard',
            'store',
            'inventory',
        ], $modules);

        $this->assertNotContains('admin_management', $modules);
        $this->assertNotContains('roles_permissions', $modules);
        $this->assertNotContains('customers', $modules);
        $this->assertNotContains('bookings', $modules);
        $this->assertNotContains('system_settings', $modules);
    }

    public function test_sales_dashboard_shows_store_when_product_permissions_are_granted(): void
    {
        $admin = Admin::factory()->create([
            'role' => AdminRole::Sales,
        ]);

        $this->assignRolePermissions(AdminRole::Sales, [
            AdminPermission::ProductsCreate,
            AdminPermission::ProductsUpdate,
        ]);

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('data.dashboard_type', 'operations')
            ->assertJsonPath('data.role', AdminRole::Sales->value)
            ->assertJsonPath('data.visible_modules', [
                'dashboard',
                'store',
            ]);
    }

    public function test_inventory_dashboard_shows_only_inventory_related_modules(): void
    {
        $admin = Admin::factory()->create([
            'role' => AdminRole::Inventory,
        ]);

        $this->assignRolePermissions(AdminRole::Inventory, [
            AdminPermission::ProductsUpdate,
            AdminPermission::SuppliersManage,
            AdminPermission::PurchaseOrdersManage,
            AdminPermission::GoodsReceiptsManage,
        ]);

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('data.dashboard_type', 'operations')
            ->assertJsonPath('data.role', AdminRole::Inventory->value)
            ->assertJsonPath('data.visible_modules', [
                'dashboard',
                'store',
                'inventory',
            ]);
    }

    public function test_accountant_without_aligned_permissions_sees_dashboard_only(): void
    {
        $admin = Admin::factory()->create([
            'role' => AdminRole::Accountant,
        ]);

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('data.dashboard_type', 'operations')
            ->assertJsonPath('data.role', AdminRole::Accountant->value)
            ->assertJsonPath('data.visible_modules', [
                'dashboard',
            ]);
    }

    public function test_direct_permission_can_unlock_additional_modules(): void
    {
        $admin = Admin::factory()->create([
            'role' => AdminRole::Sales,
        ]);

        $this->assignRolePermissions(AdminRole::Sales, [
            AdminPermission::ProductsCreate,
        ]);

        $this->assignDirectPermissions($admin, [
            AdminPermission::RolesManage,
            AdminPermission::SuppliersManage,
        ]);

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('data.dashboard_type', 'operations')
            ->assertJsonPath('data.visible_modules', [
                'dashboard',
                'roles_permissions',
                'store',
                'inventory',
            ]);
    }

    public function test_customer_token_cannot_access_admin_dashboard(): void
    {
        $user = User::factory()->create();

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_unauthenticated_access_to_admin_dashboard_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/dashboard')
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
