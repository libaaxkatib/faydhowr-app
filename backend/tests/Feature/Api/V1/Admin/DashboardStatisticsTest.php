<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Contracts\Dashboard\DashboardQueryServiceInterface;
use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceMode;
use App\Models\Admin;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\GoodsReceipt;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\StoreOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Dashboard\DashboardQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    public function test_super_admin_receives_all_statistics(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        Admin::factory()->create();
        $this->seedBusinessRecords();

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard');

        $response
            ->assertOk()
            ->assertJsonPath('data.dashboard_type', 'super_admin')
            ->assertJsonPath('data.statistics.total_admins', 2)
            ->assertJsonPath('data.statistics.total_customers', 2)
            ->assertJsonPath('data.statistics.total_bookings', 1)
            ->assertJsonPath('data.statistics.total_quotations', 1)
            ->assertJsonPath('data.statistics.total_orders', 1)
            ->assertJsonPath('data.statistics.total_store_orders', 2)
            ->assertJsonPath('data.statistics.total_products', 3)
            ->assertJsonPath('data.statistics.total_suppliers', 2)
            ->assertJsonPath('data.statistics.total_purchase_orders', 1)
            ->assertJsonPath('data.statistics.total_goods_receipts', 1)
            ->assertJsonPath('data.statistics.total_payments', 1);

        $this->assertSame(
            [
                'total_admins',
                'total_customers',
                'total_bookings',
                'total_quotations',
                'total_orders',
                'total_store_orders',
                'total_products',
                'total_suppliers',
                'total_purchase_orders',
                'total_goods_receipts',
                'total_payments',
            ],
            array_keys($response->json('data.statistics')),
        );
    }

    public function test_manager_receives_store_and_inventory_statistics(): void
    {
        $admin = Admin::factory()->create([
            'role' => AdminRole::Manager,
        ]);

        $this->assignRolePermissions(AdminRole::Manager, [
            AdminPermission::ProductsCreate,
            AdminPermission::SuppliersManage,
            AdminPermission::PurchaseOrdersManage,
            AdminPermission::GoodsReceiptsManage,
        ]);

        $this->seedBusinessRecords();

        $statistics = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.dashboard_type', 'operations')
            ->json('data.statistics');

        $this->assertArrayNotHasKey('total_admins', $statistics);
        $this->assertArrayNotHasKey('total_customers', $statistics);
        $this->assertArrayNotHasKey('total_payments', $statistics);
        $this->assertSame(2, $statistics['total_store_orders']);
        $this->assertSame(3, $statistics['total_products']);
        $this->assertSame(2, $statistics['total_suppliers']);
        $this->assertSame(1, $statistics['total_purchase_orders']);
        $this->assertSame(1, $statistics['total_goods_receipts']);
    }

    public function test_sales_receives_store_statistics_when_product_permissions_granted(): void
    {
        $admin = Admin::factory()->create([
            'role' => AdminRole::Sales,
        ]);

        $this->assignRolePermissions(AdminRole::Sales, [
            AdminPermission::ProductsCreate,
            AdminPermission::ProductsUpdate,
        ]);

        $this->seedBusinessRecords();

        $statistics = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->json('data.statistics');

        $this->assertSame([
            'total_store_orders',
            'total_products',
        ], array_keys($statistics));

        $this->assertSame(2, $statistics['total_store_orders']);
        $this->assertSame(3, $statistics['total_products']);
        $this->assertArrayNotHasKey('total_suppliers', $statistics);
        $this->assertArrayNotHasKey('total_admins', $statistics);
    }

    public function test_inventory_receives_inventory_related_statistics(): void
    {
        $admin = Admin::factory()->create([
            'role' => AdminRole::Inventory,
        ]);

        $this->assignRolePermissions(AdminRole::Inventory, [
            AdminPermission::ProductsUpdate,
            AdminPermission::SuppliersManage,
            AdminPermission::PurchaseOrdersManage,
            AdminPermission::GoodsReceiptsManage,
        ]);

        $this->seedBusinessRecords();

        $statistics = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->json('data.statistics');

        $this->assertSame([
            'total_store_orders',
            'total_products',
            'total_suppliers',
            'total_purchase_orders',
            'total_goods_receipts',
        ], array_keys($statistics));

        $this->assertSame(3, $statistics['total_products']);
        $this->assertSame(2, $statistics['total_suppliers']);
        $this->assertArrayNotHasKey('total_payments', $statistics);
        $this->assertArrayNotHasKey('total_customers', $statistics);
    }

    public function test_accountant_without_aligned_modules_receives_empty_statistics(): void
    {
        $admin = Admin::factory()->create([
            'role' => AdminRole::Accountant,
        ]);

        $this->seedBusinessRecords();

        $statistics = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->json('data.statistics');

        $this->assertSame([], $statistics);
    }

    public function test_dashboard_statistics_are_cached_for_five_minutes(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        Product::factory()->count(2)->create();

        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.statistics.total_products', 2);

        /** @var DashboardQueryService $queryService */
        $queryService = $this->app->make(DashboardQueryServiceInterface::class);
        $this->assertTrue(Cache::has($queryService->cacheKey('inventory')));

        Product::factory()->create();

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.statistics.total_products', 2);

        $this->travel(6)->minutes();

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.statistics.total_products', 3);
    }

    public function test_statistics_and_widgets_serve_identical_cached_totals(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        Product::factory()->count(2)->create();

        $token = $admin->createToken('admin-panel')->plainTextToken;

        $first = $this
            ->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        $this->assertSame(2, $first->json('data.statistics.total_products'));
        $this->assertSame(2, $first->json('data.widgets.inventory.total'));

        Product::factory()->create();

        $second = $this
            ->withToken($token)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        $this->assertSame(2, $second->json('data.statistics.total_products'));
        $this->assertSame(2, $second->json('data.widgets.inventory.total'));
    }

    public function test_customer_token_cannot_access_dashboard_statistics(): void
    {
        $user = User::factory()->create();

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_unauthenticated_access_to_dashboard_statistics_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/dashboard')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    private function seedBusinessRecords(): void
    {
        $profiles = CustomerProfile::factory()->count(2)->create();
        $profile = $profiles->first();

        $service = $this->createService();

        $modeId = DB::table('service_modes')->insertGetId([
            'service_id' => $service->id,
            'mode' => ServiceMode::OneTime->value,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Booking::query()->create([
            'booking_number' => sprintf('BK-%s-%06d', now()->format('Y'), $this->sequence++),
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
            'quotation_number' => sprintf('QT-%s-%06d', now()->format('Y'), $this->sequence++),
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
            'order_number' => sprintf('ORD-%s-%06d', now()->format('Y'), $this->sequence++),
            'customer_profile_id' => $profile->id,
            'quotation_id' => $quotation->id,
            'status' => OrderStatus::PendingPayment,
            'subtotal' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '5.00',
            'total_amount' => '95.00',
        ]);

        StoreOrder::factory()->count(2)->create([
            'customer_profile_id' => $profile->id,
        ]);

        Product::factory()->count(3)->create();
        $suppliers = Supplier::factory()->count(2)->create();
        $purchaseOrder = PurchaseOrder::factory()->approved()->create([
            'supplier_id' => $suppliers->first()->id,
        ]);
        GoodsReceipt::query()->create([
            'gr_number' => sprintf('GR-%s-%06d', now()->format('Y'), $this->sequence++),
            'supplier_id' => $suppliers->first()->id,
            'purchase_order_id' => $purchaseOrder->id,
            'received_at' => now(),
        ]);

        Payment::query()->create([
            'payment_number' => sprintf('PAY-%s-%06d', now()->format('Y'), $this->sequence++),
            'customer_profile_id' => $profile->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'status' => PaymentStatus::Initialized,
            'amount' => '95.00',
            'currency' => 'USD',
            'gateway' => 'manual',
        ]);
    }

    private function createService(): Service
    {
        $category = ServiceCategory::query()->create([
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'sort_order' => 0,
            'is_active' => true,
        ]);

        return Service::query()->create([
            'category_id' => $category->id,
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'currency' => 'USD',
            'requires_address' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
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
