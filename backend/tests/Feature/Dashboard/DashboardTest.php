<?php

namespace Tests\Feature\Dashboard;

use App\Contracts\Dashboard\DashboardWidgetInterface;
use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Permission;
use App\Models\User;
use App\Services\Dashboard\DashboardManager;
use App\Services\Dashboard\Widgets\BookingSummaryWidget;
use App\Services\Dashboard\Widgets\CustomerSummaryWidget;
use App\Services\Dashboard\Widgets\InventorySummaryWidget;
use App\Services\Dashboard\Widgets\OrderSummaryWidget;
use App\Services\Dashboard\Widgets\PaymentSummaryWidget;
use App\Services\Dashboard\Widgets\QuotationSummaryWidget;
use App\Services\Dashboard\Widgets\RevenueSummaryWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Stable public API contract keys, in widget execution order.
     *
     * @var list<string>
     */
    private const WIDGET_KEYS = [
        'bookings',
        'quotations',
        'orders',
        'payments',
        'revenue',
        'inventory',
        'customers',
    ];

    public function test_dashboard_endpoint_returns_widgets_section(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Dashboard retrieved successfully.');

        $this->assertSame(self::WIDGET_KEYS, array_keys($response->json('data.widgets')));
    }

    public function test_widget_keys_are_stable_and_payloads_are_placeholders(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $widgets = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->json('data.widgets');

        foreach (self::WIDGET_KEYS as $key) {
            $this->assertSame(['total' => 0], $widgets[$key]);
        }
    }

    public function test_dashboard_resource_structure_is_preserved(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $data = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertSame(
            [
                'dashboard_type',
                'role',
                'visible_modules',
                'visible_navigation',
                'statistics',
                'widgets',
            ],
            array_keys($data),
        );
    }

    public function test_operations_admins_also_receive_widgets(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Accountant]);

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        $this->assertSame(self::WIDGET_KEYS, array_keys($response->json('data.widgets')));
    }

    public function test_dashboard_view_permission_is_granted_to_every_operations_role(): void
    {
        $permissionId = $this->dashboardViewPermissionId();

        foreach ([AdminRole::Manager, AdminRole::Sales, AdminRole::Inventory, AdminRole::Accountant] as $role) {
            $this->assertTrue(
                DB::table('admin_role_permissions')
                    ->where('role', $role->value)
                    ->where('permission_id', $permissionId)
                    ->exists(),
                "Role [{$role->value}] is missing the dashboard.view permission.",
            );
        }
    }

    public function test_admin_authorized_through_role_permission_can_access_the_dashboard(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Sales]);

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_admin_authorized_through_direct_permission_can_access_the_dashboard(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Sales]);

        $this->revokeDashboardViewFromRole(AdminRole::Sales);

        DB::table('admin_permissions')->insert([
            'admin_id' => $admin->id,
            'permission_id' => $this->dashboardViewPermissionId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_admin_without_dashboard_view_permission_is_forbidden(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Accountant]);

        $this->revokeDashboardViewFromRole(AdminRole::Accountant);

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_super_admin_bypasses_the_dashboard_view_permission(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/dashboard')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_customers_cannot_access_the_dashboard(): void
    {
        $customer = User::factory()->create();

        $this
            ->withToken($customer->createToken('customer')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_dashboard_manager_is_container_resolved_with_all_widgets(): void
    {
        $manager = $this->app->make(DashboardManager::class);

        $this->assertSame($manager, $this->app->make(DashboardManager::class));

        $widgets = $manager->widgets();

        $this->assertCount(7, $widgets);
        $this->assertInstanceOf(BookingSummaryWidget::class, $widgets[0]);
        $this->assertInstanceOf(QuotationSummaryWidget::class, $widgets[1]);
        $this->assertInstanceOf(OrderSummaryWidget::class, $widgets[2]);
        $this->assertInstanceOf(PaymentSummaryWidget::class, $widgets[3]);
        $this->assertInstanceOf(RevenueSummaryWidget::class, $widgets[4]);
        $this->assertInstanceOf(InventorySummaryWidget::class, $widgets[5]);
        $this->assertInstanceOf(CustomerSummaryWidget::class, $widgets[6]);

        foreach ($widgets as $widget) {
            $this->assertInstanceOf(DashboardWidgetInterface::class, $widget);
        }
    }

    public function test_dashboard_manager_aggregates_widgets_in_execution_order(): void
    {
        $aggregated = $this->app->make(DashboardManager::class)->aggregate();

        $this->assertSame(self::WIDGET_KEYS, array_keys($aggregated));

        foreach ($aggregated as $payload) {
            $this->assertSame(['total' => 0], $payload);
        }
    }

    public function test_each_widget_resolves_its_placeholder_payload(): void
    {
        $expected = [
            BookingSummaryWidget::class => 'bookings',
            QuotationSummaryWidget::class => 'quotations',
            OrderSummaryWidget::class => 'orders',
            PaymentSummaryWidget::class => 'payments',
            RevenueSummaryWidget::class => 'revenue',
            InventorySummaryWidget::class => 'inventory',
            CustomerSummaryWidget::class => 'customers',
        ];

        foreach ($expected as $class => $key) {
            $widget = $this->app->make($class);

            $this->assertSame($key, $widget->key());
            $this->assertSame(['total' => 0], $widget->resolve());
        }
    }

    public function test_registering_a_new_widget_requires_no_controller_or_action_changes(): void
    {
        $extraWidget = new class implements DashboardWidgetInterface
        {
            public function key(): string
            {
                return 'extra';
            }

            /**
             * @return array<string, mixed>
             */
            public function resolve(): array
            {
                return ['total' => 0];
            }
        };

        $manager = new DashboardManager([
            $this->app->make(BookingSummaryWidget::class),
        ]);
        $manager->register($extraWidget);

        $this->assertSame(
            ['bookings' => ['total' => 0], 'extra' => ['total' => 0]],
            $manager->aggregate(),
        );
    }

    private function dashboardViewPermissionId(): int
    {
        return (int) Permission::query()
            ->where('key', AdminPermission::DashboardView->value)
            ->value('id');
    }

    private function revokeDashboardViewFromRole(AdminRole $role): void
    {
        DB::table('admin_role_permissions')
            ->where('role', $role->value)
            ->where('permission_id', $this->dashboardViewPermissionId())
            ->delete();
    }
}
