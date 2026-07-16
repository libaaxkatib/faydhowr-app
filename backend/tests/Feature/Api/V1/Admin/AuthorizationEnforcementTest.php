<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Permission;
use App\Models\User;
use App\Support\AdminPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthorizationEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_with_permission_can_access_protected_route(): void
    {
        $admin = Admin::factory()->create([
            'role' => AdminRole::Manager,
        ]);

        $this->assignDirectPermissions($admin, [
            AdminPermission::SuppliersManage,
        ]);

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/suppliers')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_admin_without_permission_is_forbidden(): void
    {
        $admin = Admin::factory()->create([
            'role' => AdminRole::Sales,
        ]);

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/suppliers')
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
                'error_code' => 'FORBIDDEN',
            ]);
    }

    public function test_super_admin_bypasses_permission_checks(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/suppliers')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/permissions')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_direct_permission_grant_allows_access(): void
    {
        $admin = Admin::factory()->create([
            'role' => AdminRole::Accountant,
        ]);

        $this->assignDirectPermissions($admin, [
            AdminPermission::PurchaseOrdersManage,
        ]);

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/purchase-orders')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_role_permission_grant_allows_access(): void
    {
        $admin = Admin::factory()->create([
            'role' => AdminRole::Inventory,
        ]);

        $this->assignRolePermissions(AdminRole::Inventory, [
            AdminPermission::GoodsReceiptsManage,
        ]);

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/goods-receipts')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_customer_token_is_rejected_on_protected_admin_routes(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $this
            ->withToken($token)
            ->getJson('/api/v1/suppliers')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this
            ->withToken($token)
            ->postJson('/api/v1/products', [
                'name' => 'Blocked Product',
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_unauthenticated_access_to_protected_routes_is_rejected(): void
    {
        $this->getJson('/api/v1/suppliers')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->getJson('/api/v1/admin/admins')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->postJson('/api/v1/products', [])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_permission_resolver_caches_keys_for_the_request_lifecycle(): void
    {
        $admin = Admin::factory()->create([
            'role' => AdminRole::Manager,
        ]);

        $this->assignRolePermissions(AdminRole::Manager, [
            AdminPermission::ProductsCreate,
        ]);
        $this->assignDirectPermissions($admin, [
            AdminPermission::ProductsUpdate,
        ]);

        $resolver = new AdminPermissionResolver;
        $request = request();

        DB::enableQueryLog();
        $first = $resolver->keysFor($admin, $request);
        $queriesAfterFirst = count(DB::getQueryLog());

        $second = $resolver->keysFor($admin, $request);
        $queriesAfterSecond = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($first, $second);
        $this->assertContains(AdminPermission::ProductsCreate->value, $first);
        $this->assertContains(AdminPermission::ProductsUpdate->value, $first);
        $this->assertSame($queriesAfterFirst, $queriesAfterSecond);
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
