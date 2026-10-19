<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P1.2 - role isolation for the PROPOSED least-privilege baselines of the three delegated Web Panel roles.
 *
 * These tests grant nothing in any real environment: each synthetic admin holds exactly the permission keys proposed in
 * `HR_P1_PERMISSION_MATRIX_AND_ROLE_BASELINE_2026-10-19.md` and the tests prove that the existing authorization already keeps
 * the modules apart - HR, Marketing and Mobile App access never leak into each other, and nobody below Super Admin reaches
 * System Administration. If a baseline is changed in the matrix, change it here first.
 */
class RoleBaselineIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** Approved baselines (owner decision, 2026-10-19): the HR Manager has full authorised payroll access and the dedicated import permission. */
    private const HR_MANAGER = ['hr.view', 'hr.manage', 'hr.reports.view', 'hr.payroll.view', 'hr.payroll.manage', 'hr.payroll.approve', 'hr.import'];

    /** The earlier, narrower HR grant set: still blocked from payroll and imports. */
    private const HR_WITHOUT_PAYROLL_OR_IMPORT = ['hr.view', 'hr.manage', 'hr.reports.view'];

    private const MARKETING_MANAGER = ['marketing.view', 'marketing.manage', 'marketing.assign', 'marketing.reports.view'];

    private const MOBILE_APP_MANAGER = ['dashboard.view', 'customers.view'];

    private function tokenFor(AdminRole $role, array $keys): string
    {
        $admin = Admin::factory()->create(['role' => $role]);

        foreach ($keys as $key) {
            DB::table('admin_permissions')->insert([
                'admin_id' => $admin->id,
                'permission_id' => Permission::query()->where('key', $key)->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->app['auth']->forgetGuards();

        return $admin->createToken('t')->plainTextToken;
    }

    private function httpStatus(string $token, string $method, string $uri): int
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($method, $uri)->getStatusCode();
    }

    /** Representative routes of every module, with the status a holder of that module's baseline must (not) get. */
    private const HR = [
        ['GET', '/api/v1/admin/hr/dashboard'],
        ['GET', '/api/v1/admin/hr/employees'],
        ['GET', '/api/v1/admin/hr/reports/summary'],
    ];

    private const HR_MONEY_AND_IMPORT = [
        ['GET', '/api/v1/admin/hr/payroll-runs'],
        ['GET', '/api/v1/admin/hr/reports/monthly-payment-list'],
        ['GET', '/api/v1/admin/hr/imports'],
    ];

    private const MARKETING = [
        ['GET', '/api/v1/admin/marketing/dashboard'],
        ['GET', '/api/v1/admin/marketing/records'],
        ['GET', '/api/v1/admin/marketing/teams'],
    ];

    private const MOBILE = [
        ['GET', '/api/v1/admin/customers'],
    ];

    private const SYSTEM = [
        ['GET', '/api/v1/admin/admins'],
        ['GET', '/api/v1/admin/audit-logs'],
        ['GET', '/api/v1/admin/permissions'],
        ['GET', '/api/v1/admin/settings'],
    ];

    public function test_the_hr_manager_baseline_reaches_hr_payroll_and_imports_but_not_marketing_mobile_or_system(): void
    {
        $token = $this->tokenFor(AdminRole::HrManager, self::HR_MANAGER);

        foreach ([...self::HR, ...self::HR_MONEY_AND_IMPORT] as [$method, $uri]) {
            $this->assertContains($this->httpStatus($token, $method, $uri), [200, 422], "HR Manager must reach $uri");
        }
        foreach ([...self::MARKETING, ...self::MOBILE, ...self::SYSTEM] as [$method, $uri]) {
            $this->assertSame(403, $this->httpStatus($token, $method, $uri), "HR Manager must not reach $uri");
        }
    }

    public function test_an_hr_grant_set_without_the_payroll_and_import_keys_reaches_neither(): void
    {
        $token = $this->tokenFor(AdminRole::HrManager, self::HR_WITHOUT_PAYROLL_OR_IMPORT);

        foreach (self::HR as [$method, $uri]) {
            $this->assertSame(200, $this->httpStatus($token, $method, $uri), "must reach $uri");
        }
        foreach (self::HR_MONEY_AND_IMPORT as [$method, $uri]) {
            $this->assertSame(403, $this->httpStatus($token, $method, $uri), "must not reach $uri");
        }
    }

    public function test_the_marketing_manager_baseline_reaches_marketing_but_not_hr_mobile_or_system(): void
    {
        $token = $this->tokenFor(AdminRole::MarketingManager, self::MARKETING_MANAGER);

        foreach (self::MARKETING as [$method, $uri]) {
            $this->assertSame(200, $this->httpStatus($token, $method, $uri), "Marketing Manager must reach $uri");
        }
        foreach ([...self::HR, ...self::HR_MONEY_AND_IMPORT, ...self::MOBILE, ...self::SYSTEM] as [$method, $uri]) {
            $this->assertSame(403, $this->httpStatus($token, $method, $uri), "Marketing Manager must not reach $uri");
        }
    }

    public function test_the_mobile_app_manager_baseline_reaches_customers_but_not_hr_marketing_or_system(): void
    {
        $token = $this->tokenFor(AdminRole::MobileAppManager, self::MOBILE_APP_MANAGER);

        foreach (self::MOBILE as [$method, $uri]) {
            $this->assertSame(200, $this->httpStatus($token, $method, $uri), "Mobile App Manager must reach $uri");
        }
        foreach ([...self::HR, ...self::HR_MONEY_AND_IMPORT, ...self::MARKETING, ...self::SYSTEM] as [$method, $uri]) {
            $this->assertSame(403, $this->httpStatus($token, $method, $uri), "Mobile App Manager must not reach $uri");
        }
        // Read-only: the baseline carries no write key.
        $this->assertSame(403, $this->httpStatus($token, 'POST', '/api/v1/admin/customers'));
    }

    public function test_roles_that_hold_no_grants_see_nothing_in_any_module(): void
    {
        foreach ([AdminRole::HrEmployee, AdminRole::MarketingEmployee, AdminRole::MarketingManager, AdminRole::MobileAppManager, AdminRole::HrManager] as $role) {
            $token = $this->tokenFor($role, []);

            foreach ([...self::HR, ...self::MARKETING, ...self::MOBILE, ...self::SYSTEM] as [$method, $uri]) {
                $this->assertSame(403, $this->httpStatus($token, $method, $uri), "{$role->value} with no grants must not reach $uri");
            }
        }
    }

    public function test_the_separate_mobile_app_roles_are_independent_of_the_web_panel_roles(): void
    {
        // Manager / Sales / Inventory / Accountant hold only what was granted to them (dashboard.view in production):
        // they reach neither HR nor Marketing nor System Administration.
        foreach ([AdminRole::Manager, AdminRole::Sales, AdminRole::Inventory, AdminRole::Accountant] as $role) {
            $token = $this->tokenFor($role, ['dashboard.view']);

            foreach ([...self::HR, ...self::MARKETING, ...self::SYSTEM] as [$method, $uri]) {
                $this->assertSame(403, $this->httpStatus($token, $method, $uri), "{$role->value} must not reach $uri");
            }
        }
    }

    public function test_super_admin_keeps_every_module(): void
    {
        $this->app['auth']->forgetGuards();
        $token = Admin::factory()->superAdmin()->create()->createToken('t')->plainTextToken;

        foreach ([...self::HR, ...self::HR_MONEY_AND_IMPORT, ...self::MARKETING, ...self::MOBILE, ...self::SYSTEM] as [$method, $uri]) {
            $this->assertContains($this->httpStatus($token, $method, $uri), [200, 422], "Super Admin must reach $uri");
        }
    }
}
