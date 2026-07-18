<?php

namespace Tests\Feature\Api\V1\StoreOrder;

use App\Actions\Payment\ConfirmOfflinePaymentAction;
use App\Actions\StoreOrder\AdvanceStoreOrderStatusAction;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\StockMovementType;
use App\Enums\StoreOrderStatus;
use App\Models\Admin;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StoreOrder;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreOrderCashOnDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_on_delivery_order_confirms_immediately_and_deducts_stock(): void
    {
        [$user, $profile] = $this->customer();
        $address = CustomerAddress::factory()->create([
            'customer_profile_id' => $profile->id,
            'is_active' => true,
        ]);
        $product = Product::factory()->create([
            'selling_price' => 12.00,
            'current_stock' => 10,
            'status' => ProductStatus::Active,
            'currency' => 'USD',
        ]);
        $cart = Cart::factory()->create(['customer_profile_id' => $profile->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/store-orders', [
                'address_id' => $address->id,
                'payment_method' => 'cash_on_delivery',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.subtotal', '36.00');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'current_stock' => 7,
        ]);
        $this->assertDatabaseHas('stock_ledgers', [
            'product_id' => $product->id,
            'movement_type' => StockMovementType::CustomerSale->value,
            'quantity' => -3,
        ]);
        $this->assertDatabaseHas('payments', [
            'customer_profile_id' => $profile->id,
            'payable_type' => StoreOrder::class,
            'status' => PaymentStatus::Pending->value,
            'payment_method' => 'cash_on_delivery',
            'payment_stage' => 'full',
            'amount' => 36,
        ]);
        $this->assertDatabaseHas('store_order_status_histories', [
            'status' => StoreOrderStatus::Confirmed->value,
            'changed_by_type' => 'user',
            'changed_by_id' => $user->id,
        ]);
    }

    public function test_cash_on_delivery_order_completes_only_after_admin_confirms_cash_collection(): void
    {
        [$storeOrder, $payment] = $this->createCashOnDeliveryOrder();
        $admin = Admin::factory()->create();
        $advance = app(AdvanceStoreOrderStatusAction::class);

        $advance->handle($admin, $storeOrder->id, StoreOrderStatus::Preparing);
        $advance->handle($admin, $storeOrder->id, StoreOrderStatus::OutForDelivery);
        $advance->handle($admin, $storeOrder->id, StoreOrderStatus::Delivered);
        $advance->handle($admin, $storeOrder->id, StoreOrderStatus::PaymentPending);

        $this->assertDatabaseHas('store_orders', [
            'id' => $storeOrder->id,
            'status' => StoreOrderStatus::PaymentPending->value,
        ]);

        $confirmedPayment = app(ConfirmOfflinePaymentAction::class)->handle($admin, $payment->id);

        $this->assertSame(PaymentStatus::Paid, $confirmedPayment->status);
        $this->assertNotNull($confirmedPayment->paid_at);
        self::assertMatchesRegularExpression(
            '/^RCPT-'.now()->format('Y').'-\d{6}$/',
            (string) $confirmedPayment->receipt_number,
        );

        $this->assertDatabaseHas('store_orders', [
            'id' => $storeOrder->id,
            'status' => StoreOrderStatus::Completed->value,
        ]);
        $this->assertDatabaseHas('store_order_status_histories', [
            'store_order_id' => $storeOrder->id,
            'status' => StoreOrderStatus::Completed->value,
            'changed_by_type' => 'admin',
            'changed_by_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('payment_status_histories', [
            'payment_id' => $payment->id,
            'status' => PaymentStatus::Paid->value,
            'changed_by_type' => 'admin',
            'changed_by_id' => $admin->id,
        ]);
    }

    public function test_cash_on_delivery_order_cannot_complete_while_the_payment_is_pending(): void
    {
        [$storeOrder] = $this->createCashOnDeliveryOrder();
        $admin = Admin::factory()->create();
        $advance = app(AdvanceStoreOrderStatusAction::class);

        $advance->handle($admin, $storeOrder->id, StoreOrderStatus::Preparing);
        $advance->handle($admin, $storeOrder->id, StoreOrderStatus::OutForDelivery);
        $advance->handle($admin, $storeOrder->id, StoreOrderStatus::Delivered);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('The order payment must be confirmed before the order can be completed.');

        $advance->handle($admin, $storeOrder->id, StoreOrderStatus::Completed);
    }

    public function test_cash_collection_cannot_be_confirmed_before_the_order_awaits_payment(): void
    {
        [, $payment] = $this->createCashOnDeliveryOrder();
        $admin = Admin::factory()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cash collection can be confirmed only while the store order awaits payment.');

        app(ConfirmOfflinePaymentAction::class)->handle($admin, $payment->id);
    }

    public function test_orders_without_a_pending_payment_cannot_move_to_payment_pending(): void
    {
        $storeOrder = StoreOrder::factory()->create([
            'status' => StoreOrderStatus::Delivered,
        ]);
        $admin = Admin::factory()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only orders awaiting payment collection can move to payment pending.');

        app(AdvanceStoreOrderStatusAction::class)->handle($admin, $storeOrder->id, StoreOrderStatus::PaymentPending);
    }

    public function test_out_of_sequence_transitions_are_rejected(): void
    {
        [$storeOrder] = $this->createCashOnDeliveryOrder();
        $admin = Admin::factory()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('This store order status transition is not allowed.');

        app(AdvanceStoreOrderStatusAction::class)->handle($admin, $storeOrder->id, StoreOrderStatus::Delivered);
    }

    public function test_confirming_a_prepaid_store_order_payment_deducts_stock_and_confirms_the_order(): void
    {
        [$user, $profile] = $this->customer();
        $address = CustomerAddress::factory()->create([
            'customer_profile_id' => $profile->id,
            'is_active' => true,
        ]);
        $product = Product::factory()->create([
            'selling_price' => 10.00,
            'current_stock' => 5,
            'status' => ProductStatus::Active,
            'currency' => 'USD',
        ]);
        $cart = Cart::factory()->create(['customer_profile_id' => $profile->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/store-orders', [
            'address_id' => $address->id,
            'payment_method' => 'bank_transfer',
        ])->assertCreated();

        $storeOrder = StoreOrder::query()->sole();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'current_stock' => 5,
        ]);

        $this->withToken($token)->postJson('/api/v1/payments/initialize', [
            'payable_type' => 'store_order',
            'payable_id' => $storeOrder->id,
            'payment_method' => 'bank_transfer',
            'payment_stage' => 'full',
            'idempotency_key' => 'cod-test-prepaid',
        ])->assertCreated();

        $payment = Payment::query()->sole();
        $admin = Admin::factory()->create();

        app(ConfirmOfflinePaymentAction::class)->handle($admin, $payment->id);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'current_stock' => 3,
        ]);
        $this->assertDatabaseHas('store_orders', [
            'id' => $storeOrder->id,
            'status' => StoreOrderStatus::Confirmed->value,
        ]);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Paid->value,
        ]);
    }

    /**
     * @return array{0: StoreOrder, 1: Payment}
     */
    private function createCashOnDeliveryOrder(): array
    {
        [$user, $profile] = $this->customer();
        $address = CustomerAddress::factory()->create([
            'customer_profile_id' => $profile->id,
            'is_active' => true,
        ]);
        $product = Product::factory()->create([
            'selling_price' => 12.00,
            'current_stock' => 10,
            'status' => ProductStatus::Active,
            'currency' => 'USD',
        ]);
        $cart = Cart::factory()->create(['customer_profile_id' => $profile->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/store-orders', [
                'address_id' => $address->id,
                'payment_method' => 'cash_on_delivery',
            ])
            ->assertCreated();

        return [StoreOrder::query()->sole(), Payment::query()->sole()];
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
