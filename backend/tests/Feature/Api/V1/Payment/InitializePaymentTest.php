<?php

namespace Tests\Feature\Api\V1\Payment;

use App\Enums\OrderStatus;
use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitializePaymentTest extends TestCase
{
    use RefreshDatabase;

    private int $quotationSequence = 1;

    public function test_customer_can_initialize_a_payment_for_an_owned_pending_order(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', [
                'order_id' => $order->id,
                'gateway' => 'manual',
                'gateway_reference' => 'INIT-001',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Payment initialized successfully.')
            ->assertJsonPath('meta', null)
            ->assertJsonPath('data.payable.type', 'order')
            ->assertJsonPath('data.payable.order_number', $order->order_number)
            ->assertJsonPath('data.status', 'initialized')
            ->assertJsonPath('data.amount', '95.00')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.gateway', 'manual');

        self::assertMatchesRegularExpression(
            '/^PAY-'.now()->format('Y').'-\d{6}$/',
            $response->json('data.payment_number'),
        );

        $this->assertDatabaseHas('payments', [
            'payment_number' => $response->json('data.payment_number'),
            'customer_profile_id' => $profile->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'status' => 'initialized',
            'amount' => 95,
            'currency' => 'USD',
            'gateway' => 'manual',
            'gateway_reference' => 'INIT-001',
        ]);
        $this->assertDatabaseHas('payment_transactions', [
            'gateway' => 'manual',
            'transaction_reference' => 'INIT-001',
            'status' => 'initialized',
        ]);
        $this->assertDatabaseHas('payment_status_histories', [
            'status' => 'initialized',
            'changed_by_type' => 'user',
            'changed_by_id' => $user->id,
        ]);
    }

    public function test_customer_cannot_initialize_a_payment_for_another_customers_order(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder(CustomerProfile::factory()->create());

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($order))
            ->assertNotFound()
            ->assertJsonPath('error_code', 'ORDER_NOT_FOUND');
    }

    public function test_customer_cannot_initialize_a_payment_for_an_order_not_pending_payment(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile, OrderStatus::Confirmed);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($order))
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'The order must be pending payment before initializing a payment.');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_initialization_requires_a_customer_profile(): void
    {
        $user = User::factory()->create();
        $order = $this->createOrder(CustomerProfile::factory()->create());

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($order))
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_PROFILE_NOT_FOUND');
    }

    public function test_payment_initialization_returns_validation_errors(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', [])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['order_id', 'gateway']]);
    }

    public function test_payment_initialization_requires_authentication(): void
    {
        $order = $this->createOrder(CustomerProfile::factory()->create());

        $this
            ->postJson('/api/v1/payments/initialize', $this->payload($order))
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_payment_initialization_returns_an_existing_active_payment_for_the_same_order(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile);
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $firstResponse = $this->withToken($token)->postJson('/api/v1/payments/initialize', $this->payload($order));
        $secondResponse = $this->withToken($token)->postJson('/api/v1/payments/initialize', $this->payload($order));

        $firstResponse->assertCreated();
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.payment_number', $firstResponse->json('data.payment_number'));

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_status_histories', 1);
    }

    public function test_payment_public_numbers_are_unique_across_orders(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $firstOrder = $this->createOrder($profile);
        $secondOrder = $this->createOrder($profile);
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $firstResponse = $this->withToken($token)->postJson('/api/v1/payments/initialize', $this->payload($firstOrder, 'INIT-001'));
        $secondResponse = $this->withToken($token)->postJson('/api/v1/payments/initialize', $this->payload($secondOrder, 'INIT-002'));

        $firstResponse->assertCreated();
        $secondResponse->assertCreated();
        self::assertNotSame(
            $firstResponse->json('data.payment_number'),
            $secondResponse->json('data.payment_number'),
        );
    }

    public function test_customer_can_initialize_a_new_payment_after_a_failed_payment(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile);
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $firstResponse = $this->withToken($token)->postJson('/api/v1/payments/initialize', $this->payload($order, 'INIT-001'));
        $firstResponse->assertCreated();

        Payment::query()
            ->where('payment_number', $firstResponse->json('data.payment_number'))
            ->update(['status' => 'failed']);

        $secondResponse = $this->withToken($token)->postJson('/api/v1/payments/initialize', $this->payload($order, 'INIT-002'));

        $secondResponse
            ->assertCreated()
            ->assertJsonPath('data.status', 'initialized');

        $this->assertDatabaseCount('payments', 2);
    }

    /**
     * @return array{order_id: int, gateway: string, gateway_reference: string}
     */
    private function payload(Order $order, string $gatewayReference = 'INIT-001'): array
    {
        return [
            'order_id' => $order->id,
            'gateway' => 'manual',
            'gateway_reference' => $gatewayReference,
        ];
    }

    private function createOrder(
        CustomerProfile $profile,
        OrderStatus $status = OrderStatus::PendingPayment,
    ): Order {
        $quotation = Quotation::query()->create([
            'quotation_number' => sprintf('QT-%s-%06d', now()->format('Y'), $this->quotationSequence++),
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

        return Order::query()->create([
            'order_number' => sprintf('ORD-%s-%06d', now()->format('Y'), $this->quotationSequence),
            'customer_profile_id' => $profile->id,
            'quotation_id' => $quotation->id,
            'status' => $status,
            'currency' => $quotation->currency,
            'subtotal' => $quotation->subtotal,
            'discount_amount' => $quotation->discount_amount,
            'tax_amount' => $quotation->tax_amount,
            'total_amount' => $quotation->total_amount,
        ]);
    }
}
