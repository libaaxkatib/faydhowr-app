<?php

namespace Tests\Feature\Api\V1\Admin\Operations;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\PaymentStage;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\StoreOrderStatus;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminStoreOrdersApiTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    public function test_listing_store_orders_requires_the_store_orders_view_permission(): void
    {
        $token = $this->tokenWithPermissions([]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/store-orders')
            ->assertForbidden();
    }

    public function test_admin_can_list_store_orders_with_a_status_filter(): void
    {
        $confirmed = StoreOrder::factory()->create(['status' => StoreOrderStatus::Confirmed]);
        StoreOrder::factory()->create(['status' => StoreOrderStatus::Cancelled]);

        $token = $this->tokenWithPermissions([AdminPermission::StoreOrdersView]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/store-orders?status=confirmed')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.items.0.store_order_number', $confirmed->store_order_number);
    }

    public function test_admin_can_view_a_store_order_detail(): void
    {
        [$storeOrder] = $this->createCashOnDeliveryOrder();

        $token = $this->tokenWithPermissions([AdminPermission::StoreOrdersView]);

        $this->withToken($token)
            ->getJson("/api/v1/admin/store-orders/{$storeOrder->id}")
            ->assertOk()
            ->assertJsonPath('data.store_order_number', $storeOrder->store_order_number)
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.payments.0.payment_method', 'cash_on_delivery');
    }

    public function test_viewing_an_unknown_store_order_returns_not_found(): void
    {
        $token = $this->tokenWithPermissions([AdminPermission::StoreOrdersView]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/store-orders/999')
            ->assertNotFound()
            ->assertJsonPath('error_code', 'STORE_ORDER_NOT_FOUND');
    }

    public function test_admin_can_advance_a_store_order_through_the_cod_fulfilment_flow(): void
    {
        [$storeOrder] = $this->createCashOnDeliveryOrder();

        $token = $this->tokenWithPermissions([AdminPermission::StoreOrdersManage]);

        foreach (['preparing', 'out_for_delivery', 'delivered', 'payment_pending'] as $status) {
            $this->withToken($token)
                ->patchJson("/api/v1/admin/store-orders/{$storeOrder->id}/status", [
                    'status' => $status,
                ])
                ->assertOk()
                ->assertJsonPath('data.status', $status);
        }

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'store_order_status_change',
            'entity_type' => StoreOrder::class,
            'entity_id' => $storeOrder->id,
        ]);
    }

    public function test_an_out_of_sequence_transition_is_rejected(): void
    {
        [$storeOrder] = $this->createCashOnDeliveryOrder();

        $token = $this->tokenWithPermissions([AdminPermission::StoreOrdersManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/store-orders/{$storeOrder->id}/status", [
                'status' => 'delivered',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'STORE_ORDER_STATE_INVALID');
    }

    public function test_an_invalid_status_value_fails_validation(): void
    {
        [$storeOrder] = $this->createCashOnDeliveryOrder();

        $token = $this->tokenWithPermissions([AdminPermission::StoreOrdersManage]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/store-orders/{$storeOrder->id}/status", [
                'status' => 'teleported',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_status_updates_require_the_store_orders_manage_permission(): void
    {
        [$storeOrder] = $this->createCashOnDeliveryOrder();

        $token = $this->tokenWithPermissions([AdminPermission::StoreOrdersView]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/store-orders/{$storeOrder->id}/status", [
                'status' => 'preparing',
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: StoreOrder, 1: Payment}
     */
    private function createCashOnDeliveryOrder(): array
    {
        $profile = CustomerProfile::factory()->create();
        $product = Product::factory()->create([
            'selling_price' => 12.00,
            'current_stock' => 7,
            'status' => ProductStatus::Active,
            'currency' => 'USD',
        ]);

        $storeOrder = StoreOrder::factory()->create([
            'customer_profile_id' => $profile->id,
            'status' => StoreOrderStatus::Confirmed,
        ]);
        StoreOrderItem::factory()->create([
            'store_order_id' => $storeOrder->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_price_snapshot' => '12.00',
            'line_total_snapshot' => '36.00',
        ]);

        $payment = Payment::query()->create([
            'payment_number' => sprintf('PAY-%s-%06d', now()->format('Y'), 900000 + $this->sequence++),
            'customer_profile_id' => $profile->id,
            'payable_type' => StoreOrder::class,
            'payable_id' => $storeOrder->id,
            'status' => PaymentStatus::Pending,
            'payment_method' => 'cash_on_delivery',
            'payment_stage' => PaymentStage::Full,
            'amount' => '36.00',
            'currency' => 'USD',
        ]);

        return [$storeOrder, $payment];
    }

    /**
     * @param  list<AdminPermission>  $permissions
     */
    private function tokenWithPermissions(array $permissions, AdminRole $role = AdminRole::Manager): string
    {
        $admin = Admin::factory()->create(['role' => $role]);

        foreach ($permissions as $permission) {
            $permissionId = Permission::query()->where('key', $permission->value)->value('id');

            DB::table('admin_permissions')->insert([
                'admin_id' => $admin->id,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $admin->createToken('admin-panel')->plainTextToken;
    }
}
