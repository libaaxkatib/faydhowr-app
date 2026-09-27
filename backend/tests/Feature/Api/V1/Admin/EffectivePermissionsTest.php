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

class EffectivePermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_effective_permissions(): void
    {
        $this->getJson('/api/v1/admin/auth/permissions')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_customer_token_cannot_access_effective_permissions(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/auth/permissions')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_super_admin_receives_full_effective_access(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/auth/permissions');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(count(AdminPermission::cases()), 'data');
    }

    public function test_hr_manager_receives_only_granted_permissions(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::HrManager]);
        $this->grantRolePermissions(AdminRole::HrManager, [AdminPermission::HrView, AdminPermission::HrManage]);

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/auth/permissions');

        // Unlike Manager/Sales/Inventory/Accountant, HR Manager gets no baseline
        // dashboard.view grant - that migration only targeted the original four
        // Mobile App roles - so exactly the two permissions granted here apply.
        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['key' => AdminPermission::HrView->value])
            ->assertJsonFragment(['key' => AdminPermission::HrManage->value])
            ->assertJsonMissing(['key' => AdminPermission::MarketingManage->value]);
    }

    public function test_marketing_manager_receives_only_granted_permissions(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::MarketingManager]);
        $this->grantRolePermissions(AdminRole::MarketingManager, [AdminPermission::MarketingView]);

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/auth/permissions');

        $response
            ->assertOk()
            ->assertJsonFragment(['key' => AdminPermission::MarketingView->value])
            ->assertJsonMissing(['key' => AdminPermission::HrManage->value]);
    }

    public function test_mobile_app_manager_with_zero_grants_receives_zero_effective_permissions(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::MobileAppManager]);

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/auth/permissions');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    public function test_direct_permission_overrides_are_reflected(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::MobileAppManager]);

        DB::table('admin_permissions')->insert([
            'admin_id' => $admin->id,
            'permission_id' => Permission::query()->where('key', AdminPermission::ReportsView->value)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/auth/permissions');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['key' => AdminPermission::ReportsView->value]);
    }

    public function test_creating_mobile_app_manager_role_does_not_auto_grant_permissions(): void
    {
        Admin::factory()->create(['role' => AdminRole::MobileAppManager]);

        $this->assertSame(
            0,
            DB::table('admin_role_permissions')->where('role', AdminRole::MobileAppManager->value)->count(),
        );
    }

    /**
     * @param  list<AdminPermission>  $permissions
     */
    private function grantRolePermissions(AdminRole $role, array $permissions): void
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
}
