<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HrFixtures;
use Tests\Support\ImportFixtures;
use Tests\TestCase;

/**
 * The HR imports are reserved for Super Admin. The restriction is enforced by the route middleware (role
 * AND permission), the permission resolver, and every grant endpoint; none of them depends on the others.
 */
class ImportAuthorizationTest extends TestCase
{
    use HrFixtures;
    use ImportFixtures;
    use RefreshDatabase;

    /** @return list<array{AdminRole}> */
    public static function nonSuperRoles(): array
    {
        return array_map(
            fn (AdminRole $role): array => [$role],
            array_values(array_filter(AdminRole::cases(), fn (AdminRole $role): bool => $role !== AdminRole::SuperAdmin)),
        );
    }

    private function endpoints(): array
    {
        return [
            ['get', self::IMPORTS],
            ['post', self::IMPORTS],
            ['get', self::IMPORTS.'/1'],
            ['get', self::IMPORTS.'/1/rows'],
            ['get', self::IMPORTS.'/1/report'],
            ['post', self::IMPORTS.'/1/confirm'],
            ['post', self::IMPORTS.'/1/process'],
            ['post', self::IMPORTS.'/1/cancel'],
        ];
    }

    public function test_the_permission_is_reserved_for_super_admin(): void
    {
        $this->assertTrue(AdminPermission::HrImport->isSuperAdminOnly());
        $this->assertSame(['hr.import'], AdminPermission::superAdminOnlyValues());
        $this->assertFalse(AdminPermission::HrManage->isSuperAdminOnly());
    }

    public function test_unauthenticated_requests_are_rejected_on_every_endpoint(): void
    {
        foreach ($this->endpoints() as [$method, $url]) {
            $this->json($method, $url)->assertUnauthorized();
        }
    }

    #[DataProvider('nonSuperRoles')]
    public function test_every_non_super_admin_role_is_denied_on_every_endpoint_even_with_all_hr_permissions(AdminRole $role): void
    {
        $admin = Admin::factory()->create(['role' => $role]);

        // Every grantable permission, role-level AND direct.
        $ids = Permission::query()->whereNotIn('key', AdminPermission::superAdminOnlyValues())->pluck('id');
        DB::table('admin_role_permissions')->insertOrIgnore($ids->map(fn ($id) => ['role' => $role->value, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now()])->all());
        $token = $admin->createToken('t')->plainTextToken;

        foreach ($this->endpoints() as [$method, $url]) {
            $this->as($token)->json($method, $url)->assertForbidden();
        }
    }

    #[DataProvider('nonSuperRoles')]
    public function test_a_stray_database_grant_of_hr_import_is_ignored_for_every_non_super_role(AdminRole $role): void
    {
        $admin = Admin::factory()->create(['role' => $role]);
        $permissionId = Permission::query()->where('key', 'hr.import')->value('id');
        $this->assertNotNull($permissionId, 'The catalogue row exists (migration).');

        // Someone writes the grant straight into the database, bypassing every endpoint.
        DB::table('admin_role_permissions')->insertOrIgnore(['role' => $role->value, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('admin_permissions')->insert(['admin_id' => $admin->id, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
        $token = $admin->createToken('t')->plainTextToken;

        $this->as($token)->getJson(self::IMPORTS)->assertForbidden();
        $this->as($token)->getJson('/api/v1/admin/auth/permissions')->assertOk();

        $keys = array_column($this->as($token)->getJson('/api/v1/admin/auth/permissions')->json('data'), 'key');
        $this->assertNotContains('hr.import', $keys, 'The resolver never honours a Super-Admin-only permission.');
    }

    public function test_hr_manager_marketing_manager_and_mobile_app_manager_are_denied_with_their_realistic_grants(): void
    {
        $grants = [
            AdminRole::HrManager->value => ['dashboard.view', 'hr.view', 'hr.manage', 'hr.reports.view'],
            AdminRole::MarketingManager->value => ['dashboard.view', 'marketing.view', 'marketing.manage'],
            AdminRole::MobileAppManager->value => ['dashboard.view', 'content.view', 'content.manage'],
        ];

        foreach ($grants as $roleValue => $keys) {
            $role = AdminRole::from($roleValue);
            $admin = Admin::factory()->create(['role' => $role]);

            foreach ($keys as $key) {
                DB::table('admin_role_permissions')->insertOrIgnore(['role' => $roleValue, 'permission_id' => Permission::query()->where('key', $key)->value('id'), 'created_at' => now(), 'updated_at' => now()]);
            }

            $token = $admin->createToken('t')->plainTextToken;
            $this->as($token)->getJson(self::IMPORTS)->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
            $this->as($token)->post(self::IMPORTS, [], ['Accept' => 'application/json'])->assertForbidden();
        }
    }

    public function test_super_admin_can_reach_the_imports(): void
    {
        [, $token] = $this->importSuperAdmin();

        $this->as($token)->getJson(self::IMPORTS)->assertOk()->assertJsonPath('data', []);
    }

    public function test_super_admin_effective_permissions_include_hr_import_but_no_other_role_does(): void
    {
        [, $super] = $this->importSuperAdmin();
        $keys = array_column($this->as($super)->getJson('/api/v1/admin/auth/permissions')->assertOk()->json('data'), 'key');
        $this->assertContains('hr.import', $keys);

        $hr = $this->tokenWithOnly(['hr.view', 'hr.manage']);
        $keys = array_column($this->as($hr)->getJson('/api/v1/admin/auth/permissions')->assertOk()->json('data'), 'key');
        $this->assertNotContains('hr.import', $keys);
    }

    public function test_the_roles_and_permissions_screen_cannot_grant_hr_import_to_a_role(): void
    {
        [, $super] = $this->importSuperAdmin();

        $this->as($super)->putJson('/api/v1/admin/roles/hr_manager/permissions', ['permissions' => ['hr.view', 'hr.import']])
            ->assertStatus(422)->assertJsonPath('error_code', 'SUPER_ADMIN_ONLY_PERMISSION');

        $this->assertSame(0, DB::table('admin_role_permissions')->where('role', 'hr_manager')->count(), 'Nothing was written.');
    }

    public function test_the_direct_admin_permission_endpoint_cannot_grant_hr_import(): void
    {
        [, $super] = $this->importSuperAdmin();
        $target = Admin::factory()->create(['role' => AdminRole::HrManager]);

        $this->as($super)->putJson("/api/v1/admin/admins/{$target->id}/permissions", ['permissions' => ['hr.import']])
            ->assertStatus(422)->assertJsonPath('error_code', 'SUPER_ADMIN_ONLY_PERMISSION');

        $this->assertSame(0, DB::table('admin_permissions')->where('admin_id', $target->id)->count());
    }

    public function test_the_grantable_catalogue_does_not_offer_hr_import(): void
    {
        [, $super] = $this->importSuperAdmin();

        $keys = array_column($this->as($super)->getJson('/api/v1/admin/permissions')->assertOk()->json('data'), 'key');

        $this->assertNotContains('hr.import', $keys);
        $this->assertContains('hr.manage', $keys);
    }

    public function test_a_non_super_admin_cannot_use_the_grant_endpoints_at_all(): void
    {
        $hr = $this->tokenWithOnly(['roles.manage']);

        $this->as($hr)->putJson('/api/v1/admin/roles/hr_manager/permissions', ['permissions' => ['hr.import']])
            ->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');
        $this->assertSame(0, DB::table('admin_role_permissions')->where('role', 'hr_manager')->count());
    }
}
