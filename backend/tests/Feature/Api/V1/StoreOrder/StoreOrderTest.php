<?php

namespace Tests\Feature\Api\V1\StoreOrder;

use App\Enums\ProductStatus;
use App\Enums\StoreOrderStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\StoreOrder;
use App\Models\StoreOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_create_a_store_order_from_cart(): void
    {
        [$user, $profile] = $this->customer();
        $address = CustomerAddress::factory()->create([
            'customer_profile_id' => $profile->id,
            'city' => 'Hargeisa',
            'is_active' => true,
        ]);
        $product = Product::factory()->create([
            'selling_price' => 15.00,
            'current_stock' => 8,
            'status' => ProductStatus::Active,
            'currency' => 'USD',
        ]);
        $cart = Cart::factory()->create(['customer_profile_id' => $profile->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/store-orders', [
                'address_id' => $address->id,
                'payment_method' => 'evc_plus',
                'notes' => 'Please call on arrival',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Store order created successfully.')
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.total_items', 1)
            ->assertJsonPath('data.total_quantity', 2)
            ->assertJsonPath('data.subtotal', '30.00')
            ->assertJsonPath('data.items.0.sku', $product->sku)
            ->assertJsonPath('data.items.0.product_name', $product->name)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price', '15.00')
            ->assertJsonPath('data.items.0.line_total', '30.00')
            ->assertJsonPath('data.shipping_address.city', 'Hargeisa')
            ->assertJsonPath('data.notes', 'Please call on arrival')
            ->assertJsonMissingPath('data.id');

        self::assertMatchesRegularExpression(
            '/^STO-'.now()->format('Y').'-\d{6}$/',
            $response->json('data.store_order_number'),
        );

        $this->assertDatabaseHas('store_orders', [
            'customer_profile_id' => $profile->id,
            'status' => StoreOrderStatus::PendingPayment->value,
            'subtotal' => 30,
            'total_quantity' => 2,
        ]);
        $this->assertDatabaseHas('store_order_items', [
            'sku_snapshot' => $product->sku,
            'quantity' => 2,
            'unit_price_snapshot' => 15,
            'line_total_snapshot' => 30,
        ]);
        $this->assertDatabaseHas('store_order_status_histories', [
            'status' => StoreOrderStatus::PendingPayment->value,
            'changed_by_type' => 'user',
            'changed_by_id' => $user->id,
        ]);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'current_stock' => 8,
        ]);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_create_store_order_rejects_empty_cart(): void
    {
        [$user, $profile] = $this->customer();
        $address = CustomerAddress::factory()->create([
            'customer_profile_id' => $profile->id,
        ]);
        Cart::factory()->create(['customer_profile_id' => $profile->id]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/store-orders', [
                'address_id' => $address->id,
                'payment_method' => 'evc_plus',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'CART_EMPTY');
    }

    public function test_create_store_order_rejects_invalid_address(): void
    {
        [$user, $profile] = $this->customer();
        $product = Product::factory()->create(['current_stock' => 5]);
        $cart = Cart::factory()->create(['customer_profile_id' => $profile->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/store-orders', [
                'address_id' => 999999,
                'payment_method' => 'evc_plus',
            ])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'ADDRESS_NOT_FOUND');
    }

    public function test_create_store_order_rejects_out_of_stock_product(): void
    {
        [$user, $profile] = $this->customer();
        $address = CustomerAddress::factory()->create([
            'customer_profile_id' => $profile->id,
        ]);
        $product = Product::factory()->create([
            'current_stock' => 1,
            'status' => ProductStatus::Active,
        ]);
        $cart = Cart::factory()->create(['customer_profile_id' => $profile->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 4,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/store-orders', [
                'address_id' => $address->id,
                'payment_method' => 'evc_plus',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'One or more cart products have insufficient stock.');
    }

    public function test_customer_can_list_and_filter_store_orders(): void
    {
        [$user, $profile] = $this->customer();
        $pending = StoreOrder::factory()->create([
            'customer_profile_id' => $profile->id,
            'status' => StoreOrderStatus::PendingPayment,
            'created_at' => now()->subMinute(),
        ]);
        $cancelled = StoreOrder::factory()->cancelled()->create([
            'customer_profile_id' => $profile->id,
            'created_at' => now(),
        ]);
        StoreOrder::factory()->create([
            'customer_profile_id' => CustomerProfile::factory()->create()->id,
        ]);

        $list = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/store-orders');

        $list
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.items.0.store_order_number', $cancelled->store_order_number)
            ->assertJsonPath('data.items.1.store_order_number', $pending->store_order_number);

        $filtered = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/store-orders?status=cancelled');

        $filtered
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.status', 'cancelled');
    }

    public function test_customer_can_view_store_order_detail(): void
    {
        [$user, $profile] = $this->customer();
        $storeOrder = StoreOrder::factory()->create([
            'customer_profile_id' => $profile->id,
            'subtotal' => 20,
        ]);
        StoreOrderItem::factory()->create([
            'store_order_id' => $storeOrder->id,
            'sku_snapshot' => 'CLN-1',
            'product_name_snapshot' => 'Cleaner',
            'quantity' => 2,
            'unit_price_snapshot' => 10,
            'line_total_snapshot' => 20,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/store-orders/'.$storeOrder->id)
            ->assertOk()
            ->assertJsonPath('data.store_order_number', $storeOrder->store_order_number)
            ->assertJsonPath('data.items.0.sku', 'CLN-1')
            ->assertJsonPath('data.items.0.line_total', '20.00');
    }

    public function test_customer_can_cancel_pending_payment_store_order(): void
    {
        [$user, $profile] = $this->customer();
        $storeOrder = StoreOrder::factory()->create([
            'customer_profile_id' => $profile->id,
            'status' => StoreOrderStatus::PendingPayment,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->patchJson('/api/v1/store-orders/'.$storeOrder->id.'/cancel', [
                'cancellation_reason' => 'Changed mind',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Changed mind');

        $this->assertDatabaseHas('store_orders', [
            'id' => $storeOrder->id,
            'status' => StoreOrderStatus::Cancelled->value,
            'cancellation_reason' => 'Changed mind',
        ]);
        $this->assertDatabaseHas('store_order_status_histories', [
            'store_order_id' => $storeOrder->id,
            'status' => StoreOrderStatus::Cancelled->value,
            'changed_by_id' => $user->id,
        ]);
    }

    public function test_customer_cannot_cancel_non_pending_store_order(): void
    {
        [$user, $profile] = $this->customer();
        $storeOrder = StoreOrder::factory()->create([
            'customer_profile_id' => $profile->id,
            'status' => StoreOrderStatus::Confirmed,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->patchJson('/api/v1/store-orders/'.$storeOrder->id.'/cancel')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'This store order cannot be cancelled.');
    }

    public function test_customer_cannot_access_another_customers_store_order(): void
    {
        [$user] = $this->customer();
        $otherOrder = StoreOrder::factory()->create([
            'customer_profile_id' => CustomerProfile::factory()->create()->id,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/store-orders/'.$otherOrder->id)
            ->assertNotFound()
            ->assertJsonPath('error_code', 'STORE_ORDER_NOT_FOUND');

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->patchJson('/api/v1/store-orders/'.$otherOrder->id.'/cancel')
            ->assertNotFound()
            ->assertJsonPath('error_code', 'STORE_ORDER_NOT_FOUND');
    }

    public function test_store_order_requires_customer_profile(): void
    {
        $user = User::factory()->create();

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/store-orders', [
                'address_id' => 1,
                'payment_method' => 'evc_plus',
            ])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_PROFILE_NOT_FOUND');
    }

    public function test_guest_cannot_access_store_orders(): void
    {
        $this->getJson('/api/v1/store-orders')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->postJson('/api/v1/store-orders', [
            'address_id' => 1,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->getJson('/api/v1/store-orders/1')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->patchJson('/api/v1/store-orders/1/cancel')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    /**
     * @return array{0: User, 1: CustomerProfile}
     */
    private function customer(): array
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);

        return [$user, $profile];
    }
}
