<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Guards the two owner expectations behind the 2026-10-29 access review, route by route instead of page by page:
 *
 *  1. An active Super Admin is never refused anything an admin route offers, and no route asks for a permission that does not exist (a typo in a
 *     route would refuse everybody, Super Admin included, with "You do not have permission").
 *  2. The HR Manager, with the permissions the role holds in production, can reach every HR and Data Issues route: nothing in the HR domain asks
 *     for a permission the role lacks or is reserved for Super Admin.
 *
 * Authorization is still decided by the server on every request; this only proves the routes and the role's permission set agree.
 */
class RoleAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** What the HR Manager role holds in production (read from the production backup of 2026-10-29). */
    private const HR_MANAGER_PRODUCTION_PERMISSIONS = [
        'hr.dashboard.view', 'hr.import', 'hr.inspections.manage', 'hr.inspections.view', 'hr.manage', 'hr.payroll.approve', 'hr.payroll.manage',
        'hr.payroll.view', 'hr.reports.view', 'hr.view', 'notifications.manage', 'reconciliation.create', 'reconciliation.resolve',
        'reconciliation.update', 'reconciliation.view', 'reports.view',
    ];

    /** @return list<LaravelRoute> */
    private function adminRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (LaravelRoute $route): bool => str_starts_with($route->uri(), 'api/v1/admin'),
        ));
    }

    /** @return list<string> the permission keys a route asks for, one per `permission:` middleware (the route needs all of them) */
    private function requiredKeys(LaravelRoute $route): array
    {
        $keys = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                array_push($keys, ...explode(',', substr($middleware, strlen('permission:'))));
            }
        }

        return $keys;
    }

    private function isSuperAdminOnlyRoute(LaravelRoute $route): bool
    {
        return in_array('super_admin', $route->gatherMiddleware(), true);
    }

    public function test_every_permission_a_route_asks_for_exists(): void
    {
        $known = AdminPermission::values();
        $unknown = [];

        foreach ($this->adminRoutes() as $route) {
            foreach ($this->requiredKeys($route) as $key) {
                if (! in_array($key, $known, true)) {
                    $unknown[] = $route->uri().' needs unknown permission '.$key;
                }
            }
        }

        $this->assertSame([], $unknown);
        $this->assertGreaterThan(100, count($this->adminRoutes()), 'the route scan found the admin API');
    }

    public function test_super_admin_is_not_refused_on_any_admin_get_route(): void
    {
        $token = Admin::factory()->superAdmin()->create()->createToken('t')->plainTextToken;
        $refused = [];

        foreach ($this->adminRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $this->app['auth']->forgetGuards();
            $status = $this->withToken($token)->getJson($uri)->getStatusCode();

            if (in_array($status, [401, 403], true)) {
                $refused[] = "{$status} {$uri}";
            }
        }

        $this->assertSame([], $refused, 'Super Admin holds every permission, so no admin GET route may refuse it');
    }

    public function test_the_hr_manager_role_covers_every_hr_and_data_issue_route(): void
    {
        $missing = [];
        $checked = 0;

        foreach ($this->adminRoutes() as $route) {
            if (! preg_match('#^api/v1/admin/(hr|reconciliation)#', $route->uri())) {
                continue;
            }

            $checked++;

            if ($this->isSuperAdminOnlyRoute($route)) {
                $missing[] = $route->uri().' is reserved for Super Admin';

                continue;
            }

            foreach ($this->requiredKeys($route) as $key) {
                $permission = AdminPermission::tryFrom($key);

                if (! in_array($key, self::HR_MANAGER_PRODUCTION_PERMISSIONS, true) || $permission?->grantableTo(AdminRole::HrManager) === false) {
                    $missing[] = $route->uri().' needs '.$key;
                }
            }
        }

        $this->assertGreaterThan(100, $checked);
        $this->assertSame([], $missing);
    }

    public function test_an_hr_manager_with_the_production_permissions_is_not_refused_on_any_hr_get_route(): void
    {
        $hr = Admin::factory()->create(['role' => AdminRole::HrManager]);

        foreach (self::HR_MANAGER_PRODUCTION_PERMISSIONS as $key) {
            DB::table('admin_role_permissions')->insertOrIgnore([
                'role' => AdminRole::HrManager->value,
                'permission_id' => Permission::query()->where('key', $key)->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $token = $hr->createToken('t')->plainTextToken;
        $refused = [];

        foreach ($this->adminRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || ! preg_match('#^api/v1/admin/(hr|reconciliation)#', $route->uri())) {
                continue;
            }

            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $this->app['auth']->forgetGuards();
            $status = $this->withToken($token)->getJson($uri)->getStatusCode();

            if (in_array($status, [401, 403], true)) {
                $refused[] = "{$status} {$uri}";
            }
        }

        $this->assertSame([], $refused);
    }

    public function test_the_hr_manager_still_cannot_use_super_admin_and_other_module_routes(): void
    {
        $hr = Admin::factory()->create(['role' => AdminRole::HrManager]);
        $token = $hr->createToken('t')->plainTextToken;

        foreach (['/api/v1/admin/admins', '/api/v1/admin/permissions', '/api/v1/admin/roles/hr_manager/permissions', '/api/v1/admin/audit-logs', '/api/v1/admin/settings'] as $uri) {
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson($uri)->assertForbidden();
        }

        // Everyone signed in reads the translation overrides (the panel needs them to render); only Super Admin changes them.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/i18n/overrides')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->putJson('/api/v1/admin/i18n/overrides/action.cancel', ['language' => 'so', 'text' => 'Jooji'])->assertForbidden();
    }
}
