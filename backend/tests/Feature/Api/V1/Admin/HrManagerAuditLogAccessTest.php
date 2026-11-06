<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Support\AdminPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Owner decision UG-4: admins.manage and roles.manage belong to the Super Admin alone, yet the HR Manager keeps READ-ONLY access to the audit log through a
 * separate role rule. Nothing here deletes or migrates a stored role-permission row: a stray grant of either permission is simply ignored.
 */
class HrManagerAuditLogAccessTest extends TestCase
{
    use RefreshDatabase;

    private function hrManager(bool $withStrayGrants = true): Admin
    {
        $admin = Admin::factory()->create(['role' => AdminRole::HrManager]);

        if ($withStrayGrants) {
            // What production may still hold: rows written straight into the tables, as the old rule allowed.
            foreach ([AdminPermission::AdminsManage, AdminPermission::RolesManage] as $permission) {
                $id = Permission::query()->where('key', $permission->value)->value('id');
                DB::table('admin_role_permissions')->insert(['role' => AdminRole::HrManager->value, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('admin_permissions')->insert(['admin_id' => $admin->id, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return $admin;
    }

    private function as(Admin $admin)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($admin->createToken('admin-panel')->plainTextToken);
    }

    private function seedAudit(Admin $by): AuditLog
    {
        event(AuditEvent::record(action: AuditAction::Export, admin: $by, description: 'Payment list downloaded.', entityType: 'App\\Models\\PayrollRun', entityId: 5));

        return AuditLog::query()->latest('id')->firstOrFail();
    }

    public function test_the_hr_manager_can_read_the_audit_log_and_its_filter_choices(): void
    {
        $hr = $this->hrManager();
        $this->seedAudit($hr);

        $this->as($hr)->getJson('/api/v1/admin/audit-logs')->assertOk()->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.action', 'export');

        $filters = $this->as($hr)->getJson('/api/v1/admin/audit-logs/filters')->assertOk();
        $this->assertContains('export', $filters->json('data.actions'), 'every audit action, including export, can be filtered');
        $this->assertSame([['value' => 'App\\Models\\PayrollRun', 'label' => 'PayrollRun']], $filters->json('data.entity_types'));
        $this->assertSame([['id' => $hr->id, 'name' => $hr->full_name]], $filters->json('data.admins'));
    }

    public function test_the_hr_manager_without_any_stray_grant_reads_the_audit_log_too(): void
    {
        $hr = $this->hrManager(false);

        $this->as($hr)->getJson('/api/v1/admin/audit-logs')->assertOk();
        $this->as($hr)->getJson('/api/v1/admin/audit-logs/filters')->assertOk();
    }

    public function test_every_admin_users_and_roles_route_is_refused_to_the_hr_manager_even_with_a_stray_database_grant(): void
    {
        $hr = $this->hrManager();
        $checked = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (! $this->isAdminOrRoleRoute($route)) {
                continue;
            }

            $uri = '/'.preg_replace(['/\{admin\}/', '/\{role\}/'], ['1', 'manager'], $route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->as($hr)->json($method, $uri, [])->assertForbidden();
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(10, $checked, 'the route scan found the Admin Users and Roles routes');
    }

    public function test_a_stray_grant_of_admins_manage_or_roles_manage_is_ignored_by_the_resolver_and_not_listed(): void
    {
        $hr = $this->hrManager();

        $keys = app(AdminPermissionResolver::class)->keysFor($hr, request());
        $this->assertNotContains('admins.manage', $keys);
        $this->assertNotContains('roles.manage', $keys);

        $super = Admin::factory()->superAdmin()->create();
        $role = $this->as($super)->getJson('/api/v1/admin/roles/hr_manager/permissions')->assertOk()->json('data.permissions');
        $this->assertNotContains('admins.manage', array_column($role, 'key'));
        $this->assertNotContains('roles.manage', array_column($role, 'key'));

        $direct = $this->as($super)->getJson("/api/v1/admin/admins/{$hr->id}/permissions")->assertOk();
        $this->assertNotContains('admins.manage', array_column($direct->json('data.direct_permissions'), 'key'));
        $this->assertNotContains('roles.manage', array_column($direct->json('data.effective_permissions'), 'key'));

        $this->assertSame(2, DB::table('admin_role_permissions')->where('role', 'hr_manager')->whereIn('permission_id', Permission::query()->whereIn('key', ['admins.manage', 'roles.manage'])->pluck('id'))->count(), 'the stored rows were not touched');

        $mine = $this->as($hr)->getJson('/api/v1/admin/auth/permissions')->assertOk();
        $this->assertNotContains('admins.manage', array_column($mine->json('data'), 'key'));
    }

    public function test_the_super_admin_still_holds_both_permissions_and_may_use_every_route(): void
    {
        $super = Admin::factory()->superAdmin()->create();
        $keys = app(AdminPermissionResolver::class)->keysFor($super, request());

        $this->assertContains('admins.manage', $keys);
        $this->assertContains('roles.manage', $keys);
        $this->as($super)->getJson('/api/v1/admin/admins')->assertOk();
        $this->as($super)->getJson('/api/v1/admin/permissions')->assertOk();
        $this->as($super)->getJson('/api/v1/admin/audit-logs')->assertOk();
        $this->as($super)->getJson('/api/v1/admin/audit-logs/filters')->assertOk();
    }

    public function test_the_permissions_cannot_be_granted_to_anybody_through_the_screens(): void
    {
        $super = Admin::factory()->superAdmin()->create();
        $hr = Admin::factory()->create(['role' => AdminRole::HrManager]);

        $this->as($super)->putJson('/api/v1/admin/roles/hr_manager/permissions', ['permissions' => ['admins.manage']])->assertStatus(422)->assertJsonPath('error_code', 'SUPER_ADMIN_ONLY_PERMISSION');
        $this->as($super)->putJson("/api/v1/admin/admins/{$hr->id}/permissions", ['permissions' => ['roles.manage']])->assertStatus(422)->assertJsonPath('error_code', 'SUPER_ADMIN_ONLY_PERMISSION');
    }

    public function test_no_audit_record_can_be_created_changed_or_deleted_through_the_api(): void
    {
        $hr = $this->hrManager();
        $log = $this->seedAudit($hr);

        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
            foreach (['/api/v1/admin/audit-logs', "/api/v1/admin/audit-logs/{$log->id}", '/api/v1/admin/audit-logs/filters'] as $uri) {
                $status = $this->as($hr)->{$method}($uri, ['description' => 'tampered'])->getStatusCode();
                $this->assertContains($status, [404, 405], "{$method} {$uri} must not exist (got {$status})");
            }
        }

        $this->assertSame('Payment list downloaded.', $log->fresh()->description);
        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_other_roles_and_anonymous_callers_are_still_refused_the_audit_log(): void
    {
        foreach ([AdminRole::Manager, AdminRole::Sales, AdminRole::HrEmployee, AdminRole::MarketingManager, AdminRole::Accountant] as $role) {
            $admin = Admin::factory()->create(['role' => $role]);
            $this->as($admin)->getJson('/api/v1/admin/audit-logs')->assertForbidden();
            $this->as($admin)->getJson('/api/v1/admin/audit-logs/filters')->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson('/api/v1/admin/audit-logs')->assertUnauthorized();
    }

    private function isAdminOrRoleRoute(LaravelRoute $route): bool
    {
        $uri = $route->uri();

        return preg_match('#^api/v1/admin/(admins|roles|permissions)(/|$)#', $uri) === 1;
    }
}
