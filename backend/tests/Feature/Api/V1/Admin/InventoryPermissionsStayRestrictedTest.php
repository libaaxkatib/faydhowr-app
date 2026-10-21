<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * BUG-11C: the Inventory permissions (products, suppliers, purchase orders, goods receipts) are not reachable through any HR or Marketing
 * permission. An HR Manager holding EVERY hr.* key (including the three added in Phase 2) and a Marketing Manager holding EVERY marketing.* key
 * are still refused by every inventory route and by the screens that hand permissions out; and no permission added in Phase 2 belongs to the
 * Inventory group.
 */
class InventoryPermissionsStayRestrictedTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const INVENTORY_KEYS = ['products.create', 'products.update', 'products.delete', 'suppliers.manage', 'purchase_orders.manage', 'goods_receipts.manage'];

    /** @return list<string> */
    private function keysStartingWith(string $prefix): array
    {
        return array_values(array_filter(AdminPermission::values(), fn (string $key): bool => str_starts_with($key, $prefix)));
    }

    public function test_every_hr_and_marketing_key_together_still_reaches_no_inventory_route_and_no_permission_screen(): void
    {
        $tokens = [
            'hr_manager' => $this->tokenForRoleWith(AdminRole::HrManager, $this->keysStartingWith('hr.')),
            'marketing_manager' => $this->tokenForRoleWith(AdminRole::MarketingManager, $this->keysStartingWith('marketing.')),
        ];

        $this->assertNotEmpty($this->keysStartingWith('hr.'));
        $this->assertNotEmpty($this->keysStartingWith('marketing.'));

        $routes = [
            ['GET', '/api/v1/suppliers'],
            ['GET', '/api/v1/purchase-orders'],
            ['GET', '/api/v1/goods-receipts'],
            ['POST', '/api/v1/products'],
            // The screens that hand permissions out.
            ['GET', '/api/v1/admin/admins'],
            ['GET', '/api/v1/admin/permissions'],
        ];

        foreach ($tokens as $who => $token) {
            foreach ($routes as [$method, $uri]) {
                $this->app['auth']->forgetGuards();
                $this->withToken($token)->json($method, $uri, [])->assertForbidden("{$who} must be refused on {$method} {$uri}");
            }
        }
    }

    public function test_a_permission_that_is_not_grantable_to_the_role_is_ignored_even_when_a_row_exists(): void
    {
        // hr.import is the one role-restricted key: a stray direct grant to another role never works (the resolver ignores it). Inventory keys
        // keep working exactly as before for the roles that hold them and for Super Admin; nothing here changes that.
        $this->assertFalse(AdminPermission::HrImport->grantableTo(AdminRole::MarketingManager));
        $this->assertTrue(AdminPermission::HrImport->grantableTo(AdminRole::HrManager));
    }

    public function test_none_of_the_permissions_added_in_phase_2_is_an_inventory_permission(): void
    {
        foreach (['hr.dashboard.view', 'hr.inspections.view', 'hr.inspections.manage'] as $key) {
            $this->assertSame('HR', AdminPermission::from($key)->group());
            $this->assertNotContains($key, self::INVENTORY_KEYS);
        }

        foreach (self::INVENTORY_KEYS as $key) {
            $this->assertNotSame('HR', AdminPermission::from($key)->group());
        }
    }
}
