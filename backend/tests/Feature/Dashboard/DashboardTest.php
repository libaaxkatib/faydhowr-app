<?php

namespace Tests\Feature\Dashboard;

use App\Actions\Admin\GetDashboardStatisticsAction;
use App\Actions\Auth\RegisterCustomerAction;
use App\Actions\Booking\CancelBookingAction;
use App\Actions\Booking\CreateBookingAction;
use App\Actions\Customer\UpdateCustomerProfileAction;
use App\Actions\GoodsReceipt\CreateGoodsReceiptAction;
use App\Actions\Order\CancelOrderAction;
use App\Actions\Order\CreateOrderAction;
use App\Actions\Payment\HandlePaymentWebhookAction;
use App\Actions\Payment\InitializePaymentAction;
use App\Actions\Payment\ProcessPaymentAction;
use App\Actions\Product\CreateProductAction;
use App\Actions\Product\DeleteProductAction;
use App\Actions\Product\UpdateProductAction;
use App\Actions\PurchaseOrder\ApprovePurchaseOrderAction;
use App\Actions\PurchaseOrder\CancelPurchaseOrderAction;
use App\Actions\PurchaseOrder\CreatePurchaseOrderAction;
use App\Actions\PurchaseOrder\SubmitPurchaseOrderAction;
use App\Actions\PurchaseOrder\UpdatePurchaseOrderAction;
use App\Actions\Quotation\AcceptQuotationAction;
use App\Actions\Quotation\CreateQuotationAction;
use App\Actions\StoreOrder\CancelStoreOrderAction;
use App\Actions\StoreOrder\CreateStoreOrderAction;
use App\Actions\StoreOrder\InitializeStoreOrderPaymentAction;
use App\Actions\Supplier\CreateSupplierAction;
use App\Actions\Supplier\DeleteSupplierAction;
use App\Actions\Supplier\UpdateSupplierAction;
use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Contracts\Dashboard\DashboardMetadataBuilderInterface;
use App\Contracts\Dashboard\DashboardQueryServiceInterface;
use App\Contracts\Dashboard\DashboardWidgetInterface;
use App\Contracts\Dashboard\DashboardWidgetRegistryInterface;
use App\DataTransferObjects\Dashboard\DashboardCacheData;
use App\DataTransferObjects\Dashboard\DashboardFilterData;
use App\DataTransferObjects\Dashboard\DashboardKpiData;
use App\DataTransferObjects\Dashboard\DashboardMetadataData;
use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\BookingStatus;
use App\Enums\DashboardDateFilter;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceMode;
use App\Models\Admin;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Supplier;
use App\Models\User;
use App\Repositories\Reports\BookingReportRepository;
use App\Repositories\Reports\CustomerReportRepository;
use App\Repositories\Reports\GoodsReceiptReportRepository;
use App\Repositories\Reports\InventoryReportRepository;
use App\Repositories\Reports\OrderReportRepository;
use App\Repositories\Reports\PaymentReportRepository;
use App\Repositories\Reports\PurchaseOrderReportRepository;
use App\Repositories\Reports\QuotationReportRepository;
use App\Repositories\Reports\StoreOrderReportRepository;
use App\Repositories\Reports\SupplierReportRepository;
use App\Services\Dashboard\DashboardManager;
use App\Services\Dashboard\DashboardQueryService;
use App\Services\Dashboard\DashboardWidgetRegistry;
use App\Services\Dashboard\Widgets\BookingSummaryWidget;
use App\Services\Dashboard\Widgets\CustomerSummaryWidget;
use App\Services\Dashboard\Widgets\InventorySummaryWidget;
use App\Services\Dashboard\Widgets\OrderSummaryWidget;
use App\Services\Dashboard\Widgets\PaymentSummaryWidget;
use App\Services\Dashboard\Widgets\QuotationSummaryWidget;
use App\Services\Dashboard\Widgets\RevenueSummaryWidget;
use Carbon\CarbonImmutable;
use Error;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionNamedType;
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

    /**
     * Stable KPI payload keys, in order.
     *
     * @var list<string>
     */
    private const KPI_KEYS = ['total', 'label', 'unit', 'updated_at'];

    /**
     * Expected label and unit per widget key.
     *
     * @var array<string, array{label: string, unit: string}>
     */
    private const KPI_META = [
        'bookings' => ['label' => 'Bookings', 'unit' => 'records'],
        'quotations' => ['label' => 'Quotations', 'unit' => 'records'],
        'orders' => ['label' => 'Orders', 'unit' => 'records'],
        'payments' => ['label' => 'Payments', 'unit' => 'records'],
        'revenue' => ['label' => 'Revenue', 'unit' => 'currency'],
        'inventory' => ['label' => 'Products', 'unit' => 'records'],
        'customers' => ['label' => 'Customers', 'unit' => 'records'],
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

    public function test_widget_keys_are_stable_and_totals_are_zero_without_data(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $widgets = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->json('data.widgets');

        foreach (self::WIDGET_KEYS as $key) {
            $this->assertSame(self::KPI_KEYS, array_keys($widgets[$key]));
            $this->assertEquals(0, $widgets[$key]['total']);
        }
    }

    public function test_widgets_return_kpi_payloads_with_labels_and_units(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $widgets = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->json('data.widgets');

        foreach (self::KPI_META as $key => $meta) {
            $this->assertSame(self::KPI_KEYS, array_keys($widgets[$key]));
            $this->assertSame($meta['label'], $widgets[$key]['label']);
            $this->assertSame($meta['unit'], $widgets[$key]['unit']);
            $this->assertSame(
                $widgets[$key]['updated_at'],
                CarbonImmutable::parse($widgets[$key]['updated_at'])->toIso8601String(),
                "Widget [{$key}] updated_at must be an ISO-8601 timestamp.",
            );
        }
    }

    public function test_dashboard_widgets_return_real_totals_through_the_endpoint(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->seedBusinessRecords();

        $widgets = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->json('data.widgets');

        $this->assertSame(1, $widgets['bookings']['total']);
        $this->assertSame(1, $widgets['quotations']['total']);
        $this->assertSame(1, $widgets['orders']['total']);
        $this->assertSame(2, $widgets['payments']['total']);
        $this->assertSame(145.5, $widgets['revenue']['total']);
        $this->assertSame(3, $widgets['inventory']['total']);
        $this->assertSame(2, $widgets['customers']['total']);
    }

    public function test_query_service_computes_totals_from_repositories(): void
    {
        $this->seedBusinessRecords();

        $service = $this->app->make(DashboardQueryServiceInterface::class);

        $this->assertSame(1, $service->bookingSummary()->total);
        $this->assertSame(1, $service->quotationSummary()->total);
        $this->assertSame(1, $service->orderSummary()->total);
        $this->assertSame(2, $service->paymentSummary()->total);
        $this->assertSame(145.5, $service->revenueSummary()->total);
        $this->assertSame(3, $service->inventorySummary()->total);
        $this->assertSame(2, $service->customerSummary()->total);
    }

    public function test_dashboard_response_includes_metadata(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $meta = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->json('meta');

        $this->assertSame(['generated_at', 'cache', 'filter', 'version'], array_keys($meta));
        $this->assertSame(
            $meta['generated_at'],
            CarbonImmutable::parse($meta['generated_at'])->toIso8601String(),
            'meta.generated_at must be an ISO-8601 timestamp.',
        );
        $this->assertSame(['enabled' => true, 'ttl_seconds' => 300], $meta['cache']);
        $this->assertSame(
            ['type' => 'all_time', 'start_date' => null, 'end_date' => null],
            $meta['filter'],
        );
        $this->assertSame(1, $meta['version']);
    }

    public function test_metadata_reflects_the_active_filter(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard?filter=today')
            ->assertOk()
            ->assertJsonPath('meta.filter.type', 'today')
            ->assertJsonPath('meta.filter.start_date', null)
            ->assertJsonPath('meta.filter.end_date', null);
    }

    public function test_metadata_includes_custom_date_range_bounds(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $startDate = CarbonImmutable::now()->subDays(10)->toDateString();
        $endDate = CarbonImmutable::now()->subDays(5)->toDateString();

        $meta = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson("/api/v1/admin/dashboard?filter=custom_date_range&start_date={$startDate}&end_date={$endDate}")
            ->assertOk()
            ->assertJsonPath('meta.filter.type', 'custom_date_range')
            ->json('meta.filter');

        $this->assertSame($startDate, CarbonImmutable::parse($meta['start_date'])->toDateString());
        $this->assertSame($endDate, CarbonImmutable::parse($meta['end_date'])->toDateString());
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
            $this->assertInstanceOf(DashboardKpiData::class, $payload);
            $this->assertSame(self::KPI_KEYS, array_keys($payload->toArray()));
            $this->assertEquals(0, $payload->total);
        }
    }

    public function test_each_widget_resolves_its_summary_through_the_query_service(): void
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
            $payload = $widget->resolve();

            $this->assertSame($key, $widget->key());
            $this->assertInstanceOf(DashboardKpiData::class, $payload);
            $this->assertSame(self::KPI_KEYS, array_keys($payload->toArray()));
            $this->assertEquals(0, $payload->total);
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

            public function resolve(): DashboardKpiData
            {
                return new DashboardKpiData(
                    total: 0,
                    label: 'Extra',
                    unit: 'records',
                    updatedAt: CarbonImmutable::now()->toIso8601String(),
                );
            }
        };

        $registry = new DashboardWidgetRegistry([
            $this->app->make(BookingSummaryWidget::class),
        ]);
        $registry->register($extraWidget);

        $aggregated = (new DashboardManager($registry))->aggregate();

        $this->assertSame(['bookings', 'extra'], array_keys($aggregated));
        $this->assertSame(0, $aggregated['bookings']->total);
        $this->assertSame(0, $aggregated['extra']->total);
        $this->assertSame('Extra', $aggregated['extra']->label);
    }

    public function test_widget_registry_is_container_resolved_with_all_widgets_in_order(): void
    {
        $registry = $this->app->make(DashboardWidgetRegistryInterface::class);

        $this->assertInstanceOf(DashboardWidgetRegistry::class, $registry);
        $this->assertSame($registry, $this->app->make(DashboardWidgetRegistryInterface::class));

        $this->assertSame(
            self::WIDGET_KEYS,
            array_map(
                static fn (DashboardWidgetInterface $widget): string => $widget->key(),
                $registry->enabled(),
            ),
        );
    }

    public function test_widget_registry_preserves_registration_order(): void
    {
        $registry = new DashboardWidgetRegistry;
        $registry->register($this->app->make(CustomerSummaryWidget::class));
        $registry->register($this->app->make(BookingSummaryWidget::class));
        $registry->register($this->app->make(RevenueSummaryWidget::class));

        $this->assertSame(
            ['customers', 'bookings', 'revenue'],
            array_map(
                static fn (DashboardWidgetInterface $widget): string => $widget->key(),
                $registry->enabled(),
            ),
        );
    }

    public function test_dashboard_manager_depends_only_on_the_registry_interface(): void
    {
        $parameters = (new ReflectionClass(DashboardManager::class))
            ->getConstructor()
            ->getParameters();

        $this->assertCount(1, $parameters);

        $type = $parameters[0]->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(DashboardWidgetRegistryInterface::class, $type->getName());
    }

    public function test_query_service_interface_is_bound_to_the_query_service_as_a_singleton(): void
    {
        $service = $this->app->make(DashboardQueryServiceInterface::class);

        $this->assertInstanceOf(DashboardQueryService::class, $service);
        $this->assertSame($service, $this->app->make(DashboardQueryServiceInterface::class));
    }

    public function test_widgets_depend_only_on_the_query_service_interface(): void
    {
        $widgetClasses = [
            BookingSummaryWidget::class,
            QuotationSummaryWidget::class,
            OrderSummaryWidget::class,
            PaymentSummaryWidget::class,
            RevenueSummaryWidget::class,
            InventorySummaryWidget::class,
            CustomerSummaryWidget::class,
        ];

        foreach ($widgetClasses as $widgetClass) {
            $parameters = (new ReflectionClass($widgetClass))
                ->getConstructor()
                ->getParameters();

            $this->assertCount(1, $parameters, "{$widgetClass} must depend on the query service only.");

            $type = $parameters[0]->getType();

            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $this->assertSame(DashboardQueryServiceInterface::class, $type->getName());
        }
    }

    public function test_no_widget_receives_a_repository_dependency(): void
    {
        $widgetClasses = [
            BookingSummaryWidget::class,
            QuotationSummaryWidget::class,
            OrderSummaryWidget::class,
            PaymentSummaryWidget::class,
            RevenueSummaryWidget::class,
            InventorySummaryWidget::class,
            CustomerSummaryWidget::class,
        ];

        foreach ($widgetClasses as $widgetClass) {
            foreach ((new ReflectionClass($widgetClass))->getConstructor()->getParameters() as $parameter) {
                $type = $parameter->getType();

                $this->assertInstanceOf(ReflectionNamedType::class, $type);
                $this->assertStringStartsNotWith(
                    'App\\Repositories\\',
                    $type->getName(),
                    "{$widgetClass} must never inject a repository.",
                );
            }
        }
    }

    public function test_query_service_owns_the_repository_dependencies(): void
    {
        $parameterTypes = array_map(
            function ($parameter): string {
                $type = $parameter->getType();

                $this->assertInstanceOf(ReflectionNamedType::class, $type);

                return $type->getName();
            },
            (new ReflectionClass(DashboardQueryService::class))
                ->getConstructor()
                ->getParameters(),
        );

        $this->assertSame(
            [
                BookingReportRepository::class,
                QuotationReportRepository::class,
                OrderReportRepository::class,
                PaymentReportRepository::class,
                InventoryReportRepository::class,
                CustomerReportRepository::class,
                StoreOrderReportRepository::class,
                SupplierReportRepository::class,
                PurchaseOrderReportRepository::class,
                GoodsReceiptReportRepository::class,
                DashboardCacheInvalidatorInterface::class,
            ],
            $parameterTypes,
        );
    }

    public function test_widget_payloads_originate_from_the_query_service(): void
    {
        $fakeQueryService = new class implements DashboardQueryServiceInterface
        {
            public function applyDateFilter(
                ?DashboardDateFilter $filter,
                ?CarbonImmutable $startDate = null,
                ?CarbonImmutable $endDate = null,
            ): void {
                // Date filtering is irrelevant to this fake.
            }

            private function marker(string $summary): DashboardKpiData
            {
                return new DashboardKpiData(
                    total: 0,
                    label: "query_service:{$summary}",
                    unit: 'records',
                    updatedAt: CarbonImmutable::now()->toIso8601String(),
                );
            }

            public function bookingSummary(): DashboardKpiData
            {
                return $this->marker('bookings');
            }

            public function quotationSummary(): DashboardKpiData
            {
                return $this->marker('quotations');
            }

            public function orderSummary(): DashboardKpiData
            {
                return $this->marker('orders');
            }

            public function paymentSummary(): DashboardKpiData
            {
                return $this->marker('payments');
            }

            public function revenueSummary(): DashboardKpiData
            {
                return $this->marker('revenue');
            }

            public function inventorySummary(): DashboardKpiData
            {
                return $this->marker('inventory');
            }

            public function customerSummary(): DashboardKpiData
            {
                return $this->marker('customers');
            }

            public function adminSummary(): DashboardKpiData
            {
                return $this->marker('admins');
            }

            public function storeOrderSummary(): DashboardKpiData
            {
                return $this->marker('store_orders');
            }

            public function supplierSummary(): DashboardKpiData
            {
                return $this->marker('suppliers');
            }

            public function purchaseOrderSummary(): DashboardKpiData
            {
                return $this->marker('purchase_orders');
            }

            public function goodsReceiptSummary(): DashboardKpiData
            {
                return $this->marker('goods_receipts');
            }
        };

        $this->app->instance(DashboardQueryServiceInterface::class, $fakeQueryService);

        $aggregated = $this->app->make(DashboardManager::class)->aggregate();

        foreach (self::WIDGET_KEYS as $key) {
            $this->assertSame("query_service:{$key}", $aggregated[$key]->label);
        }
    }

    public function test_query_service_returns_zero_totals_without_data(): void
    {
        $service = $this->app->make(DashboardQueryServiceInterface::class);

        $this->assertSame(0, $service->bookingSummary()->total);
        $this->assertSame(0, $service->quotationSummary()->total);
        $this->assertSame(0, $service->orderSummary()->total);
        $this->assertSame(0, $service->paymentSummary()->total);
        $this->assertSame(0.0, $service->revenueSummary()->total);
        $this->assertSame(0, $service->inventorySummary()->total);
        $this->assertSame(0, $service->customerSummary()->total);
    }

    public function test_dashboard_defaults_to_all_time_totals_without_a_filter(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->createProductsCreatedAt(2, CarbonImmutable::now());
        $this->createProductsCreatedAt(3, CarbonImmutable::now()->subDays(40));

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.widgets.inventory.total', 5);
    }

    public function test_today_filter_limits_totals_to_todays_records(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->createProductsCreatedAt(2, CarbonImmutable::now());
        $this->createProductsCreatedAt(1, CarbonImmutable::now()->subDay());
        $this->createProductsCreatedAt(3, CarbonImmutable::now()->subDays(40));

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard?filter=today')
            ->assertOk()
            ->assertJsonPath('data.widgets.inventory.total', 2);
    }

    public function test_last_7_days_filter_limits_totals_to_the_last_seven_days(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->createProductsCreatedAt(1, CarbonImmutable::now());
        $this->createProductsCreatedAt(2, CarbonImmutable::now()->subDays(3));
        $this->createProductsCreatedAt(4, CarbonImmutable::now()->subDays(10));

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard?filter=last_7_days')
            ->assertOk()
            ->assertJsonPath('data.widgets.inventory.total', 3);
    }

    public function test_this_month_filter_limits_totals_to_the_current_month(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->createProductsCreatedAt(1, CarbonImmutable::now()->startOfMonth());
        $this->createProductsCreatedAt(1, CarbonImmutable::now());
        $this->createProductsCreatedAt(5, CarbonImmutable::now()->subDays(45));

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard?filter=this_month')
            ->assertOk()
            ->assertJsonPath('data.widgets.inventory.total', 2);
    }

    public function test_custom_date_range_filter_limits_totals_to_the_range(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->createProductsCreatedAt(2, CarbonImmutable::now()->subDays(10));
        $this->createProductsCreatedAt(1, CarbonImmutable::now()->subDays(2));
        $this->createProductsCreatedAt(1, CarbonImmutable::now());

        $startDate = CarbonImmutable::now()->subDays(12)->toDateString();
        $endDate = CarbonImmutable::now()->subDays(5)->toDateString();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson("/api/v1/admin/dashboard?filter=custom_date_range&start_date={$startDate}&end_date={$endDate}")
            ->assertOk()
            ->assertJsonPath('data.widgets.inventory.total', 2);
    }

    public function test_date_filter_applies_to_every_widget(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->seedBusinessRecords();

        Payment::query()->latest('id')->firstOrFail()
            ->forceFill(['created_at' => CarbonImmutable::now()->subDays(40)])
            ->save();

        $widgets = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard?filter=last_7_days')
            ->assertOk()
            ->json('data.widgets');

        $this->assertSame(1, $widgets['bookings']['total']);
        $this->assertSame(1, $widgets['quotations']['total']);
        $this->assertSame(1, $widgets['orders']['total']);
        $this->assertSame(1, $widgets['payments']['total']);
        $this->assertEquals(95.0, $widgets['revenue']['total']);
        $this->assertSame(3, $widgets['inventory']['total']);
        $this->assertSame(2, $widgets['customers']['total']);
    }

    public function test_invalid_filter_is_rejected(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard?filter=not_a_filter')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_custom_date_range_requires_start_and_end_dates(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard?filter=custom_date_range')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_custom_date_range_rejects_end_date_before_start_date(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $startDate = CarbonImmutable::now()->toDateString();
        $endDate = CarbonImmutable::now()->subDays(3)->toDateString();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson("/api/v1/admin/dashboard?filter=custom_date_range&start_date={$startDate}&end_date={$endDate}")
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_query_service_resolves_predefined_ranges(): void
    {
        $this->createProductsCreatedAt(1, CarbonImmutable::now());
        $this->createProductsCreatedAt(1, CarbonImmutable::now()->subDay());
        $this->createProductsCreatedAt(1, CarbonImmutable::now()->subDays(20));
        $this->createProductsCreatedAt(1, CarbonImmutable::now()->subDays(60));

        $service = $this->app->make(DashboardQueryServiceInterface::class);

        $service->applyDateFilter(DashboardDateFilter::Today);
        $this->assertSame(1, $service->inventorySummary()->total);

        $service->applyDateFilter(DashboardDateFilter::Yesterday);
        $this->assertSame(1, $service->inventorySummary()->total);

        $service->applyDateFilter(DashboardDateFilter::Last30Days);
        $this->assertSame(3, $service->inventorySummary()->total);

        $service->applyDateFilter(null);
        $this->assertSame(4, $service->inventorySummary()->total);
    }

    public function test_cache_miss_loads_the_repository_and_cache_hit_skips_it(): void
    {
        $this->mock(BookingReportRepository::class, function ($mock): void {
            $mock->shouldReceive('summary')
                ->once()
                ->andReturn(['total_records' => 7]);
        });

        $service = $this->app->make(DashboardQueryServiceInterface::class);

        $first = $service->bookingSummary();
        $second = $service->bookingSummary();

        $this->assertSame(7, $first->total);
        $this->assertSame($first->toArray(), $second->toArray());
    }

    public function test_cached_summaries_are_served_until_the_ttl_expires(): void
    {
        Product::factory()->create();

        $service = $this->app->make(DashboardQueryServiceInterface::class);

        $firstPayload = $service->inventorySummary();
        $this->assertSame(1, $firstPayload->total);

        Product::factory()->create();

        $this->assertSame($firstPayload->toArray(), $service->inventorySummary()->toArray());

        $this->travel(6)->minutes();

        $refreshed = $service->inventorySummary();
        $this->assertSame(2, $refreshed->total);
        $this->assertNotSame($firstPayload->updatedAt, $refreshed->updatedAt);
    }

    public function test_different_date_filters_use_different_cache_entries(): void
    {
        $this->createProductsCreatedAt(1, CarbonImmutable::now());
        $this->createProductsCreatedAt(1, CarbonImmutable::now()->subDays(40));

        /** @var DashboardQueryService $service */
        $service = $this->app->make(DashboardQueryServiceInterface::class);

        $service->applyDateFilter(DashboardDateFilter::Today);
        $todayKey = $service->cacheKey('inventory');
        $this->assertSame(1, $service->inventorySummary()->total);

        $service->applyDateFilter(null);
        $allTimeKey = $service->cacheKey('inventory');
        $this->assertSame(2, $service->inventorySummary()->total);

        $this->assertNotSame($todayKey, $allTimeKey);
        $this->assertTrue(Cache::has($todayKey));
        $this->assertTrue(Cache::has($allTimeKey));
    }

    public function test_different_custom_date_ranges_use_different_cache_entries(): void
    {
        $this->createProductsCreatedAt(2, CarbonImmutable::now()->subDays(10));
        $this->createProductsCreatedAt(1, CarbonImmutable::now()->subDays(2));

        /** @var DashboardQueryService $service */
        $service = $this->app->make(DashboardQueryServiceInterface::class);

        $service->applyDateFilter(
            DashboardDateFilter::CustomDateRange,
            CarbonImmutable::now()->subDays(12),
            CarbonImmutable::now()->subDays(5),
        );
        $olderRangeKey = $service->cacheKey('inventory');
        $this->assertSame(2, $service->inventorySummary()->total);

        $service->applyDateFilter(
            DashboardDateFilter::CustomDateRange,
            CarbonImmutable::now()->subDays(3),
            CarbonImmutable::now(),
        );
        $recentRangeKey = $service->cacheKey('inventory');
        $this->assertSame(1, $service->inventorySummary()->total);

        $this->assertNotSame($olderRangeKey, $recentRangeKey);

        $service->applyDateFilter(
            DashboardDateFilter::CustomDateRange,
            CarbonImmutable::now()->subDays(12),
            CarbonImmutable::now()->subDays(5),
        );
        $this->assertSame(2, $service->inventorySummary()->total);
    }

    public function test_endpoint_serves_cached_widget_totals(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        Product::factory()->create();

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.widgets.inventory.total', 1);

        Product::factory()->create();

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.widgets.inventory.total', 1);
    }

    public function test_dashboard_dtos_are_readonly(): void
    {
        $dtoClasses = [
            DashboardKpiData::class,
            DashboardMetadataData::class,
            DashboardCacheData::class,
            DashboardFilterData::class,
        ];

        foreach ($dtoClasses as $dtoClass) {
            $this->assertTrue(
                (new ReflectionClass($dtoClass))->isReadOnly(),
                "{$dtoClass} must be a readonly class.",
            );
        }
    }

    public function test_kpi_dto_properties_cannot_be_mutated(): void
    {
        $kpi = new DashboardKpiData(
            total: 5,
            label: 'Bookings',
            unit: 'records',
            updatedAt: CarbonImmutable::now()->toIso8601String(),
        );

        $this->expectException(Error::class);

        $kpi->total = 99;
    }

    public function test_kpi_dto_serializes_to_the_kpi_payload(): void
    {
        $kpi = new DashboardKpiData(
            total: 120,
            label: 'Bookings',
            unit: 'records',
            updatedAt: '2026-07-17T09:00:00+00:00',
        );

        $expected = [
            'total' => 120,
            'label' => 'Bookings',
            'unit' => 'records',
            'updated_at' => '2026-07-17T09:00:00+00:00',
        ];

        $this->assertSame($expected, $kpi->toArray());
        $this->assertSame($expected, $kpi->jsonSerialize());
    }

    public function test_metadata_dto_serializes_to_the_metadata_payload(): void
    {
        $metadata = new DashboardMetadataData(
            generatedAt: '2026-07-17T09:00:00+00:00',
            cache: new DashboardCacheData(enabled: true, ttlSeconds: 300),
            filter: new DashboardFilterData(type: 'all_time', startDate: null, endDate: null),
            version: 1,
        );

        $this->assertSame(
            [
                'generated_at' => '2026-07-17T09:00:00+00:00',
                'cache' => ['enabled' => true, 'ttl_seconds' => 300],
                'filter' => ['type' => 'all_time', 'start_date' => null, 'end_date' => null],
                'version' => 1,
            ],
            $metadata->toArray(),
        );
    }

    public function test_query_service_returns_kpi_dtos(): void
    {
        $service = $this->app->make(DashboardQueryServiceInterface::class);

        $summaries = [
            $service->bookingSummary(),
            $service->quotationSummary(),
            $service->orderSummary(),
            $service->paymentSummary(),
            $service->revenueSummary(),
            $service->inventorySummary(),
            $service->customerSummary(),
        ];

        foreach ($summaries as $summary) {
            $this->assertInstanceOf(DashboardKpiData::class, $summary);
        }
    }

    public function test_metadata_builder_returns_a_metadata_dto(): void
    {
        $metadata = $this->app
            ->make(DashboardMetadataBuilderInterface::class)
            ->build(DashboardDateFilter::Today);

        $this->assertInstanceOf(DashboardMetadataData::class, $metadata);
        $this->assertInstanceOf(DashboardCacheData::class, $metadata->cache);
        $this->assertInstanceOf(DashboardFilterData::class, $metadata->filter);
        $this->assertSame('today', $metadata->filter->type);
        $this->assertSame(300, $metadata->cache->ttlSeconds);
        $this->assertSame(1, $metadata->version);
    }

    public function test_statistics_and_widgets_share_the_same_filter_context(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->createProductsCreatedAt(2, CarbonImmutable::now());
        $this->createProductsCreatedAt(3, CarbonImmutable::now()->subDays(40));

        $data = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard?filter=today')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['statistics']['total_products']);
        $this->assertSame(2, $data['widgets']['inventory']['total']);
    }

    public function test_statistics_change_correctly_for_every_supported_filter(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-20 12:00:00'));

        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this->createProductsCreatedAt(1, CarbonImmutable::parse('2026-05-20 08:00:00'));
        $this->createProductsCreatedAt(1, CarbonImmutable::parse('2026-05-19 12:00:00'));
        $this->createProductsCreatedAt(1, CarbonImmutable::parse('2026-05-15 12:00:00'));
        $this->createProductsCreatedAt(1, CarbonImmutable::parse('2026-05-01 12:00:00'));
        $this->createProductsCreatedAt(1, CarbonImmutable::parse('2026-04-10 12:00:00'));
        $this->createProductsCreatedAt(1, CarbonImmutable::parse('2026-02-01 12:00:00'));

        $scenarios = [
            '' => 6,
            '?filter=today' => 1,
            '?filter=yesterday' => 1,
            '?filter=last_7_days' => 3,
            '?filter=last_30_days' => 4,
            '?filter=this_month' => 4,
            '?filter=last_month' => 1,
            '?filter=custom_date_range&start_date=2026-04-01&end_date=2026-04-30' => 1,
        ];

        foreach ($scenarios as $query => $expectedTotal) {
            $data = $this
                ->withToken($token)
                ->getJson("/api/v1/admin/dashboard{$query}")
                ->assertOk()
                ->json('data');

            $this->assertSame(
                $expectedTotal,
                $data['statistics']['total_products'],
                "Statistics for [{$query}] must reflect the filter.",
            );
            $this->assertSame(
                $expectedTotal,
                $data['widgets']['inventory']['total'],
                "Widgets for [{$query}] must match the statistics.",
            );
        }
    }

    public function test_statistics_and_widgets_do_not_duplicate_repository_calls(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $this->mock(BookingReportRepository::class, function ($mock): void {
            $mock->shouldReceive('summary')
                ->once()
                ->andReturn(['total_records' => 7]);
        });

        $data = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertSame(7, $data['statistics']['total_bookings']);
        $this->assertSame(7, $data['widgets']['bookings']['total']);
    }

    public function test_statistics_no_longer_maintain_an_independent_calculation_path(): void
    {
        $parameters = (new ReflectionClass(GetDashboardStatisticsAction::class))
            ->getConstructor()
            ->getParameters();

        $this->assertCount(1, $parameters);

        $type = $parameters[0]->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(DashboardQueryServiceInterface::class, $type->getName());
    }

    public function test_dashboard_cache_invalidates_after_create(): void
    {
        $service = $this->app->make(DashboardQueryServiceInterface::class);

        $this->assertSame(0, $service->supplierSummary()->total);
        $this->assertTrue(Cache::has($service->cacheKey('suppliers')));

        $this->app->make(CreateSupplierAction::class)->handle(['name' => 'Acme Supplies']);

        $this->assertFalse(
            Cache::has($service->cacheKey('suppliers')),
            'Creating a supplier must invalidate the dashboard cache immediately.',
        );
        $this->assertSame(1, $service->supplierSummary()->total);
    }

    public function test_dashboard_cache_invalidates_after_update(): void
    {
        $supplier = Supplier::factory()->create();
        $service = $this->app->make(DashboardQueryServiceInterface::class);

        $service->supplierSummary();
        $this->assertTrue(Cache::has($service->cacheKey('suppliers')));

        $this->app->make(UpdateSupplierAction::class)->handle($supplier, ['name' => 'Renamed Supplies']);

        $this->assertFalse(
            Cache::has($service->cacheKey('suppliers')),
            'Updating a supplier must invalidate the dashboard cache immediately.',
        );
    }

    public function test_dashboard_cache_invalidates_after_delete(): void
    {
        $supplier = Supplier::factory()->create();
        $service = $this->app->make(DashboardQueryServiceInterface::class);

        $this->assertSame(1, $service->supplierSummary()->total);

        $this->app->make(DeleteSupplierAction::class)->handle($supplier);

        $this->assertFalse(
            Cache::has($service->cacheKey('suppliers')),
            'Deleting a supplier must invalidate the dashboard cache immediately.',
        );
        $this->assertSame(0, $service->supplierSummary()->total);
    }

    public function test_invalidation_clears_every_cached_filter_variant(): void
    {
        $service = $this->app->make(DashboardQueryServiceInterface::class);

        $service->applyDateFilter(null);
        $service->supplierSummary();
        $allTimeKey = $service->cacheKey('suppliers');

        $service->applyDateFilter(DashboardDateFilter::Today);
        $service->supplierSummary();
        $todayKey = $service->cacheKey('suppliers');

        $this->assertTrue(Cache::has($allTimeKey));
        $this->assertTrue(Cache::has($todayKey));

        $this->app->make(CreateSupplierAction::class)->handle(['name' => 'Acme Supplies']);

        $this->assertFalse(Cache::has($allTimeKey), 'The all-time cache entry must be invalidated.');
        $this->assertFalse(Cache::has($todayKey), 'The today cache entry must be invalidated.');
    }

    public function test_unrelated_caches_remain_after_invalidation(): void
    {
        Cache::put('unrelated:cache-entry', 'untouched', 600);

        $service = $this->app->make(DashboardQueryServiceInterface::class);
        $service->supplierSummary();

        $this->app->make(CreateSupplierAction::class)->handle(['name' => 'Acme Supplies']);

        $this->assertFalse(Cache::has($service->cacheKey('suppliers')));
        $this->assertSame(
            'untouched',
            Cache::get('unrelated:cache-entry'),
            'Invalidation must never flush caches outside the dashboard.',
        );
    }

    public function test_dashboard_repopulates_cache_correctly_after_invalidation(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $data = $this
            ->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $data['statistics']['total_suppliers']);

        $this->app->make(CreateSupplierAction::class)->handle(['name' => 'Acme Supplies']);

        $data = $this
            ->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertSame(
            1,
            $data['statistics']['total_suppliers'],
            'The dashboard must serve fresh totals immediately after a mutation.',
        );

        $service = $this->app->make(DashboardQueryServiceInterface::class);

        $this->assertTrue(
            Cache::has($service->cacheKey('suppliers')),
            'The dashboard must repopulate the cache after invalidation.',
        );

        $data = $this
            ->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['statistics']['total_suppliers']);
    }

    public function test_business_mutation_actions_call_the_reusable_invalidator(): void
    {
        $mutationActions = [
            RegisterCustomerAction::class,
            CreateBookingAction::class,
            CancelBookingAction::class,
            UpdateCustomerProfileAction::class,
            CreateGoodsReceiptAction::class,
            CreateOrderAction::class,
            CancelOrderAction::class,
            InitializePaymentAction::class,
            ProcessPaymentAction::class,
            HandlePaymentWebhookAction::class,
            CreateProductAction::class,
            UpdateProductAction::class,
            DeleteProductAction::class,
            CreatePurchaseOrderAction::class,
            UpdatePurchaseOrderAction::class,
            SubmitPurchaseOrderAction::class,
            ApprovePurchaseOrderAction::class,
            CancelPurchaseOrderAction::class,
            CreateQuotationAction::class,
            AcceptQuotationAction::class,
            CreateStoreOrderAction::class,
            CancelStoreOrderAction::class,
            InitializeStoreOrderPaymentAction::class,
            CreateSupplierAction::class,
            UpdateSupplierAction::class,
            DeleteSupplierAction::class,
        ];

        foreach ($mutationActions as $actionClass) {
            $dependencyTypes = array_map(
                static fn ($parameter): string => $parameter->getType() instanceof ReflectionNamedType
                    ? $parameter->getType()->getName()
                    : '',
                (new ReflectionClass($actionClass))->getConstructor()->getParameters(),
            );

            $this->assertContains(
                DashboardCacheInvalidatorInterface::class,
                $dependencyTypes,
                "{$actionClass} must depend on the reusable dashboard cache invalidator.",
            );
        }
    }

    private function createProductsCreatedAt(int $count, CarbonImmutable $createdAt): void
    {
        Product::factory()
            ->count($count)
            ->create()
            ->each(
                fn (Product $product) => $product->forceFill(['created_at' => $createdAt])->save(),
            );
    }

    /**
     * Seeds one booking, one quotation, one order, two payments (95.00 + 50.50),
     * three products, and two customer profiles.
     */
    private function seedBusinessRecords(): void
    {
        $profiles = CustomerProfile::factory()->count(2)->create();
        $profile = $profiles->first();

        $category = ServiceCategory::query()->create([
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $service = Service::query()->create([
            'category_id' => $category->id,
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'currency' => 'USD',
            'requires_address' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $modeId = DB::table('service_modes')->insertGetId([
            'service_id' => $service->id,
            'mode' => ServiceMode::OneTime->value,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Booking::query()->create([
            'booking_number' => sprintf('BK-%s-000001', now()->format('Y')),
            'customer_profile_id' => $profile->id,
            'service_id' => $service->id,
            'service_mode_id' => $modeId,
            'status' => BookingStatus::Submitted,
            'requested_date' => now()->addWeek()->toDateString(),
            'requested_time_window' => '09:00-12:00',
            'address_snapshot' => [
                'line1' => 'KM4 Road',
                'city' => 'Mogadishu',
                'country_code' => 'SO',
            ],
        ]);

        $quotation = Quotation::query()->create([
            'quotation_number' => sprintf('QT-%s-000001', now()->format('Y')),
            'customer_profile_id' => $profile->id,
            'status' => QuotationStatus::Accepted,
            'currency' => 'USD',
            'subtotal' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '5.00',
            'total_amount' => '95.00',
            'valid_until' => now()->addWeek(),
            'accepted_at' => now(),
        ]);

        $order = Order::query()->create([
            'order_number' => sprintf('ORD-%s-000001', now()->format('Y')),
            'customer_profile_id' => $profile->id,
            'quotation_id' => $quotation->id,
            'status' => OrderStatus::PendingPayment,
            'subtotal' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '5.00',
            'total_amount' => '95.00',
        ]);

        Payment::query()->create([
            'payment_number' => sprintf('PAY-%s-000001', now()->format('Y')),
            'customer_profile_id' => $profile->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'status' => PaymentStatus::Initialized,
            'amount' => '95.00',
            'currency' => 'USD',
            'gateway' => 'manual',
        ]);

        Payment::query()->create([
            'payment_number' => sprintf('PAY-%s-000002', now()->format('Y')),
            'customer_profile_id' => $profile->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'status' => PaymentStatus::Initialized,
            'amount' => '50.50',
            'currency' => 'USD',
            'gateway' => 'manual',
        ]);

        Product::factory()->count(3)->create();
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
