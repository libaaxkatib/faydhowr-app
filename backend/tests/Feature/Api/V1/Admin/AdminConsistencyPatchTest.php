<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Contracts\Dashboard\DashboardQueryServiceInterface;
use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\AdminStatus;
use App\Enums\AuditAction;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Product;
use App\Services\Dashboard\DashboardQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminConsistencyPatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_admin_token_is_rejected_on_protected_admin_endpoints(): void
    {
        $admin = Admin::factory()->superAdmin()->create([
            'status' => AdminStatus::Active,
        ]);

        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/auth/me')
            ->assertOk();

        $admin->update(['status' => AdminStatus::Inactive]);

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/auth/me')
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => 'Admin account is inactive.',
                'error_code' => 'ADMIN_ACCOUNT_INACTIVE',
            ]);

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ADMIN_ACCOUNT_INACTIVE');
    }

    public function test_admin_delete_dispatches_delete_audit_event(): void
    {
        $actor = Admin::factory()->superAdmin()->create();
        $target = Admin::factory()->create([
            'role' => AdminRole::Sales,
        ]);

        $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->deleteJson("/api/v1/admin/admins/{$target->id}")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $actor->id,
            'action' => AuditAction::Delete->value,
            'entity_type' => Admin::class,
            'entity_id' => $target->id,
            'description' => 'Admin account deleted.',
        ]);
    }

    public function test_role_permission_update_dispatches_role_update_audit_event(): void
    {
        $actor = Admin::factory()->superAdmin()->create();

        $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->putJson('/api/v1/admin/roles/manager/permissions', [
                'permissions' => [AdminPermission::ProductsCreate->value],
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $actor->id,
            'action' => AuditAction::RoleUpdate->value,
            'entity_type' => 'role',
            'description' => 'Role permissions updated.',
        ]);

        $log = AuditLog::query()
            ->where('action', AuditAction::RoleUpdate->value)
            ->firstOrFail();

        $this->assertSame(AdminRole::Manager->value, $log->metadata['role']);
        $this->assertSame([AdminPermission::ProductsCreate->value], $log->metadata['permissions']);
    }

    public function test_direct_admin_permission_update_dispatches_permission_update_audit_event(): void
    {
        $actor = Admin::factory()->superAdmin()->create();
        $target = Admin::factory()->create([
            'role' => AdminRole::Sales,
        ]);

        $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->putJson("/api/v1/admin/admins/{$target->id}/permissions", [
                'permissions' => [AdminPermission::SuppliersManage->value],
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $actor->id,
            'action' => AuditAction::PermissionUpdate->value,
            'entity_type' => Admin::class,
            'entity_id' => $target->id,
            'description' => 'Direct admin permissions updated.',
        ]);
    }

    public function test_dashboard_statistics_use_the_unified_dashboard_cache(): void
    {
        $actor = Admin::factory()->superAdmin()->create();
        Product::factory()->count(2)->create();

        $token = $actor->createToken('admin-panel')->plainTextToken;

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.statistics.total_products', 2)
            ->assertJsonPath('data.widgets.inventory.total', 2);

        /** @var DashboardQueryService $queryService */
        $queryService = $this->app->make(DashboardQueryServiceInterface::class);
        $this->assertTrue(Cache::has($queryService->cacheKey('inventory')));

        Product::factory()->create();

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.statistics.total_products', 2)
            ->assertJsonPath('data.widgets.inventory.total', 2);

        $this->travel(6)->minutes();

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.statistics.total_products', 3)
            ->assertJsonPath('data.widgets.inventory.total', 3);
    }

    public function test_permission_updates_change_statistics_visibility_immediately(): void
    {
        $actor = Admin::factory()->superAdmin()->create();
        $target = Admin::factory()->create([
            'role' => AdminRole::Inventory,
        ]);
        $actorToken = $actor->createToken('admin-panel')->plainTextToken;
        $targetToken = $target->createToken('admin-panel')->plainTextToken;

        $statistics = $this
            ->withToken($targetToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->json('data.statistics');

        $this->assertArrayNotHasKey('total_suppliers', $statistics);

        // Sanctum caches the resolved user per guard within a single test,
        // so switching bearer tokens requires flushing the guards first.
        $this->app['auth']->forgetGuards();

        $this
            ->withToken($actorToken)
            ->putJson('/api/v1/admin/roles/inventory/permissions', [
                'permissions' => [
                    AdminPermission::DashboardView->value,
                    AdminPermission::SuppliersManage->value,
                ],
            ])
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this
            ->withToken($targetToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.statistics.total_suppliers', 0);
    }

    public function test_admin_password_is_hashed_once_via_model_cast(): void
    {
        $actor = Admin::factory()->superAdmin()->create();
        $plainPassword = 'SingleHashPass1';

        $response = $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/admin/admins', [
                'full_name' => 'Hash Check Admin',
                'email' => 'hash.check@example.com',
                'phone' => '+252610000888',
                'password' => $plainPassword,
                'role' => AdminRole::Manager->value,
                'status' => AdminStatus::Active->value,
            ])
            ->assertCreated();

        $created = Admin::query()->findOrFail($response->json('data.id'));
        $storedHash = $created->getRawOriginal('password');

        $this->assertNotSame($plainPassword, $storedHash);
        $this->assertTrue(Hash::isHashed($storedHash));
        $this->assertTrue(Hash::check($plainPassword, $storedHash));
    }

    public function test_permission_catalog_matches_protected_admin_route_keys(): void
    {
        $catalog = AdminPermission::values();
        sort($catalog);

        $this->assertSame([
            'accounting.view',
            'admins.manage',
            'customers.attachments',
            'customers.create',
            'customers.delete',
            'customers.notes',
            'customers.restore',
            'customers.update',
            'customers.view',
            'dashboard.view',
            'goods_receipts.manage',
            'notifications.manage',
            'products.create',
            'products.delete',
            'products.update',
            'purchase_orders.manage',
            'reports.view',
            'roles.manage',
            'settings.manage',
            'settings.view',
            'suppliers.manage',
        ], $catalog);

        $this->assertSame(
            $catalog,
            Permission::query()->orderBy('key')->pluck('key')->all(),
        );

        $routePermissions = collect(Route::getRoutes())
            ->flatMap(function ($route): array {
                $middlewares = $route->gatherMiddleware();

                return collect($middlewares)
                    ->filter(fn (string $middleware): bool => str_starts_with($middleware, 'permission:'))
                    ->map(fn (string $middleware): string => substr($middleware, strlen('permission:')))
                    ->all();
            })
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame($catalog, $routePermissions);
        $this->assertDatabaseCount('permissions', count($catalog));
        $this->assertDatabaseMissing('permissions', ['key' => 'products.view']);
        $this->assertDatabaseHas('permissions', ['key' => 'customers.view']);
        $this->assertSame(0, DB::table('permissions')->whereNotIn('key', $catalog)->count());
    }
}
