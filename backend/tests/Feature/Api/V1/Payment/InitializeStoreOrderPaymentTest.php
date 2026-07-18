<?php

namespace Tests\Feature\Api\V1\Payment;

use App\Enums\StoreOrderStatus;
use App\Models\CustomerProfile;
use App\Models\Payment;
use App\Models\StoreOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitializeStoreOrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_initialize_a_payment_for_an_owned_pending_store_order(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $storeOrder = StoreOrder::factory()->create([
            'customer_profile_id' => $profile->id,
            'status' => StoreOrderStatus::PendingPayment,
            'subtotal' => 42.50,
            'currency' => 'USD',
        ]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($storeOrder));

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Payment initialized successfully.')
            ->assertJsonPath('meta', null)
            ->assertJsonPath('data.payable.type', 'store_order')
            ->assertJsonPath('data.payable.store_order_number', $storeOrder->store_order_number)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment_method', 'edahab')
            ->assertJsonPath('data.payment_stage', 'full')
            ->assertJsonPath('data.amount', '42.50')
            ->assertJsonPath('data.currency', 'USD');

        self::assertMatchesRegularExpression(
            '/^PAY-'.now()->format('Y').'-\d{6}$/',
            $response->json('data.payment_number'),
        );

        $this->assertDatabaseHas('payments', [
            'payment_number' => $response->json('data.payment_number'),
            'customer_profile_id' => $profile->id,
            'payable_type' => StoreOrder::class,
            'payable_id' => $storeOrder->id,
            'status' => 'pending',
            'payment_method' => 'edahab',
            'payment_stage' => 'full',
            'amount' => 42.50,
            'currency' => 'USD',
        ]);
        $this->assertDatabaseHas('payment_status_histories', [
            'status' => 'pending',
            'changed_by_type' => 'user',
            'changed_by_id' => $user->id,
        ]);

        $this->assertDatabaseHas('store_orders', [
            'id' => $storeOrder->id,
            'status' => StoreOrderStatus::PendingPayment->value,
        ]);
    }

    public function test_store_order_payments_reject_a_non_full_stage(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $storeOrder = StoreOrder::factory()->create([
            'customer_profile_id' => $profile->id,
            'status' => StoreOrderStatus::PendingPayment,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($storeOrder, stage: 'deposit'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Store order payments support the full payment stage only.');
    }

    public function test_store_order_payments_reject_cash_on_service(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $storeOrder = StoreOrder::factory()->create([
            'customer_profile_id' => $profile->id,
            'status' => StoreOrderStatus::PendingPayment,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($storeOrder, method: 'cash_on_service'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cash on service is available for cleaning services only.');
    }

    public function test_store_order_payments_reject_manual_cash_on_delivery_initialization(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $storeOrder = StoreOrder::factory()->create([
            'customer_profile_id' => $profile->id,
            'status' => StoreOrderStatus::PendingPayment,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($storeOrder, method: 'cash_on_delivery'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cash on delivery payments are created with the store order.');
    }

    public function test_payment_initialization_returns_an_existing_active_payment_for_the_same_store_order(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $storeOrder = StoreOrder::factory()->create([
            'customer_profile_id' => $profile->id,
        ]);
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $firstResponse = $this->withToken($token)->postJson(
            '/api/v1/payments/initialize',
            $this->payload($storeOrder, idempotencyKey: 'sto-idem-1'),
        );
        $secondResponse = $this->withToken($token)->postJson(
            '/api/v1/payments/initialize',
            $this->payload($storeOrder, idempotencyKey: 'sto-idem-2'),
        );

        $firstResponse->assertCreated();
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.payment_number', $firstResponse->json('data.payment_number'));

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_status_histories', 1);
    }

    public function test_customer_cannot_initialize_a_payment_for_a_store_order_not_pending_payment(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $storeOrder = StoreOrder::factory()->create([
            'customer_profile_id' => $profile->id,
            'status' => StoreOrderStatus::Confirmed,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($storeOrder))
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'message',
                'The store order must be pending payment before initializing a payment.',
            );

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('store_orders', [
            'id' => $storeOrder->id,
            'status' => StoreOrderStatus::Confirmed->value,
        ]);
    }

    public function test_customer_cannot_initialize_a_payment_for_another_customers_store_order(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);
        $storeOrder = StoreOrder::factory()->create([
            'customer_profile_id' => CustomerProfile::factory()->create()->id,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($storeOrder))
            ->assertNotFound()
            ->assertJsonPath('error_code', 'STORE_ORDER_NOT_FOUND');
    }

    public function test_store_order_payment_initialization_requires_a_customer_profile(): void
    {
        $user = User::factory()->create();
        $storeOrder = StoreOrder::factory()->create([
            'customer_profile_id' => CustomerProfile::factory()->create()->id,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($storeOrder))
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_PROFILE_NOT_FOUND');
    }

    public function test_store_order_payment_initialization_requires_authentication(): void
    {
        $storeOrder = StoreOrder::factory()->create();

        $this
            ->postJson('/api/v1/payments/initialize', $this->payload($storeOrder))
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_customer_can_initialize_a_new_store_order_payment_after_a_failed_payment(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $storeOrder = StoreOrder::factory()->create([
            'customer_profile_id' => $profile->id,
        ]);
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $firstResponse = $this->withToken($token)->postJson(
            '/api/v1/payments/initialize',
            $this->payload($storeOrder, idempotencyKey: 'sto-idem-1'),
        );
        $firstResponse->assertCreated();

        Payment::query()
            ->where('payment_number', $firstResponse->json('data.payment_number'))
            ->update(['status' => 'failed']);

        $secondResponse = $this->withToken($token)->postJson(
            '/api/v1/payments/initialize',
            $this->payload($storeOrder, idempotencyKey: 'sto-idem-2'),
        );

        $secondResponse
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('store_orders', [
            'id' => $storeOrder->id,
            'status' => StoreOrderStatus::PendingPayment->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        StoreOrder $storeOrder,
        string $method = 'edahab',
        string $stage = 'full',
        string $idempotencyKey = 'sto-idem-001',
    ): array {
        return [
            'payable_type' => 'store_order',
            'payable_id' => $storeOrder->id,
            'payment_method' => $method,
            'payment_stage' => $stage,
            'idempotency_key' => $idempotencyKey,
        ];
    }
}
