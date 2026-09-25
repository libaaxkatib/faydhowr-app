<?php

namespace Tests\Feature\Api\V1\Admin\Marketing;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\MarketingRecord;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 7 security audit (P7-10): the Marketing test suite had zero
 * authorization-boundary assertions (no 401, no 403 tests at all). This
 * mirrors HrAuthorizationBoundaryTest.php's approach for the Marketing
 * permission keys. No business rule, route, or permission key is changed -
 * this file only adds test coverage.
 */
class MarketingAuthorizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function noPermissionToken(): string
    {
        return Admin::factory()->create(['role' => AdminRole::Sales])->createToken('t')->plainTextToken;
    }

    private function tokenWithOnly(AdminPermission $permission): string
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Manager]);

        DB::table('admin_permissions')->insert([
            'admin_id' => $admin->id,
            'permission_id' => Permission::query()->where('key', $permission->value)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $admin->createToken('t')->plainTextToken;
    }

    private function marketingViewRoutes(): array
    {
        return [
            ['get', '/api/v1/admin/marketing/dashboard'],
            ['get', '/api/v1/admin/marketing/teams'],
            ['get', '/api/v1/admin/marketing/records'],
            ['get', '/api/v1/admin/marketing/follow-ups'],
            ['get', '/api/v1/admin/marketing/employees'],
        ];
    }

    private function marketingManageRoutes(): array
    {
        return [
            ['post', '/api/v1/admin/marketing/teams', []],
            ['post', '/api/v1/admin/marketing/xarun', []],
            ['post', '/api/v1/admin/marketing/project', []],
            ['post', '/api/v1/admin/marketing/commission/rates', []],
        ];
    }

    public function test_marketing_view_routes_reject_a_request_with_no_token_at_all(): void
    {
        foreach ($this->marketingViewRoutes() as [$method, $uri]) {
            $this->json($method, $uri)->assertStatus(401);
        }
    }

    public function test_marketing_manage_routes_reject_a_request_with_no_token_at_all(): void
    {
        foreach ($this->marketingManageRoutes() as [$method, $uri, $body]) {
            $this->json($method, $uri, $body)->assertStatus(401);
        }
    }

    public function test_marketing_view_routes_reject_an_admin_with_no_marketing_permission(): void
    {
        $token = $this->noPermissionToken();

        foreach ($this->marketingViewRoutes() as [$method, $uri]) {
            $this->withToken($token)->json($method, $uri)->assertStatus(403);
        }
    }

    public function test_marketing_manage_routes_reject_an_admin_with_only_marketing_view(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::MarketingView);

        foreach ($this->marketingManageRoutes() as [$method, $uri, $body]) {
            $this->withToken($token)->json($method, $uri, $body)->assertStatus(403);
        }
    }

    public function test_marketing_reports_route_rejects_an_admin_with_only_marketing_view(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::MarketingView);

        $this->withToken($token)
            ->getJson('/api/v1/admin/marketing/reports/summary')
            ->assertStatus(403);
    }

    public function test_marketing_commission_route_rejects_an_admin_with_only_marketing_view(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::MarketingView);

        $this->withToken($token)
            ->getJson('/api/v1/admin/marketing/commission/rates')
            ->assertStatus(403);
    }

    public function test_marketing_assign_route_rejects_an_admin_with_only_marketing_view(): void
    {
        // Arranged directly via Eloquent (not an authenticated HTTP call) so
        // this test exercises exactly one admin identity per request.
        $record = MarketingRecord::query()->create([
            'record_number' => 'REC-'.fake()->unique()->numerify('######'),
            'type' => 'xarun',
        ]);

        $token = $this->tokenWithOnly(AdminPermission::MarketingView);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/marketing/records/{$record->id}/assign", [])
            ->assertStatus(403);
    }
}
