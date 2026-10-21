<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RBAC fix verification: an HR Manager with the intended HR grant
 * (hr.view + hr.manage + hr.reports.view) can use HR functionality and gets
 * a clean 403 from every non-HR module, while a freshly-created HR Manager
 * with no grant at all (today's default - see EffectivePermissionsTest) is
 * correctly blocked everywhere, including HR itself. Super Admin, Marketing
 * Manager, and Mobile App Manager behavior is untouched by this fix - the
 * backend's permission:<key> middleware and AdminPermissionResolver are not
 * modified, only exercised here under the three new roles.
 */
class HrManagerCrossModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The intended HR Manager grant. `DashboardView` is included deliberately:
     * GET /api/v1/admin/dashboard - the generic Dashboard page every nav
     * includes unconditionally - itself requires permission:dashboard.view
     * (routes/api.php), a requirement the original 2026-07-17 migration only
     * ever granted to the legacy Manager/Sales/Inventory/Accountant roles.
     * Without it, "HR Manager must see Dashboard" would still 403 even after
     * the hr.* grants are correct - this is part of the minimal intended set,
     * not an unrelated extra permission.
     */
    private const HR_PERMISSIONS = [
        AdminPermission::DashboardView,
        AdminPermission::HrDashboardView,
        AdminPermission::HrView,
        AdminPermission::HrManage,
        AdminPermission::HrReportsView,
    ];

    public function test_hr_manager_with_intended_grant_can_use_hr_dashboard_employees_and_reports(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::HrManager]);
        $this->assignRolePermissions(AdminRole::HrManager, self::HR_PERMISSIONS);
        $token = $admin->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/hr/dashboard')->assertOk();
        $this->withToken($token)->getJson('/api/v1/admin/hr/employees')->assertOk();
        $this->withToken($token)->getJson('/api/v1/admin/hr/reports/summary')->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.visible_modules', ['dashboard', 'hr']);
    }

    public function test_hr_manager_without_hr_reports_view_is_blocked_from_reports_only(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::HrManager]);
        $this->assignRolePermissions(AdminRole::HrManager, [
            AdminPermission::HrDashboardView,
            AdminPermission::HrView,
            AdminPermission::HrManage,
        ]);
        $token = $admin->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/hr/dashboard')->assertOk();
        $this->withToken($token)
            ->getJson('/api/v1/admin/hr/reports/summary')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_hr_manager_with_intended_grant_still_cannot_access_non_hr_modules(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::HrManager]);
        $this->assignRolePermissions(AdminRole::HrManager, self::HR_PERMISSIONS);
        $token = $admin->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/marketing/dashboard')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/customers')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/admins')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/reconciliation/data-issues')->assertForbidden();

        // The dashboard's own module list must match: HR only, nothing else - this is
        // the same signal the (fixed) sidebar/router now use for nav/route gating.
        $this->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.visible_modules', ['dashboard', 'hr']);
    }

    public function test_hr_manager_with_zero_grants_is_blocked_everywhere_including_hr(): void
    {
        // Today's actual default for a freshly-created role - see
        // EffectivePermissionsTest::test_mobile_app_manager_with_zero_grants_receives_zero_effective_permissions
        // for the equivalent assertion on another role. This is Part B of the
        // audited root cause: granting hr.view/hr.manage/hr.reports.view is a
        // manual step, not a seeded default.
        $admin = Admin::factory()->create(['role' => AdminRole::HrManager]);
        $token = $admin->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/hr/dashboard')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/hr/employees')->assertForbidden();

        // No dashboard.view either (nothing was granted) - the generic Dashboard
        // page's own route-level permission check rejects the call outright,
        // it never even reaches the module-visibility list below it.
        $this->withToken($token)->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_super_admin_dashboard_and_hr_access_are_unaffected(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/hr/dashboard')->assertOk();
        $this->withToken($token)->getJson('/api/v1/admin/marketing/dashboard')->assertOk();
        $this->withToken($token)->getJson('/api/v1/admin/admins')->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.dashboard_type', 'super_admin');
    }

    public function test_marketing_manager_access_is_unaffected_by_the_hr_fix(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::MarketingManager]);
        $this->assignRolePermissions(AdminRole::MarketingManager, [
            AdminPermission::DashboardView,
            AdminPermission::MarketingView,
            AdminPermission::MarketingManage,
        ]);
        $token = $admin->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/marketing/dashboard')->assertOk();
        $this->withToken($token)->getJson('/api/v1/admin/hr/dashboard')->assertForbidden();

        $this->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.visible_modules', ['dashboard', 'marketing']);
    }

    public function test_mobile_app_manager_with_zero_grants_is_blocked_everywhere(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::MobileAppManager]);
        $token = $admin->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/customers')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/hr/dashboard')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/admin/dashboard')->assertForbidden();
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
}
