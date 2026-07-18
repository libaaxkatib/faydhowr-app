<?php

namespace Tests\Feature\Api\V1\Payment;

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStage;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceMode;
use App\Enums\ServicePaymentType;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitializePaymentTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    public function test_customer_can_initialize_a_full_payment_for_an_owned_pending_order(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($order));

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Payment initialized successfully.')
            ->assertJsonPath('meta', null)
            ->assertJsonPath('data.payable.type', 'order')
            ->assertJsonPath('data.payable.order_number', $order->order_number)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment_method', 'evc_plus')
            ->assertJsonPath('data.payment_stage', 'full')
            ->assertJsonPath('data.amount', '95.00')
            ->assertJsonPath('data.currency', 'USD');

        self::assertMatchesRegularExpression(
            '/^PAY-'.now()->format('Y').'-\d{6}$/',
            $response->json('data.payment_number'),
        );

        $this->assertDatabaseHas('payments', [
            'payment_number' => $response->json('data.payment_number'),
            'customer_profile_id' => $profile->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'status' => 'pending',
            'payment_method' => 'evc_plus',
            'payment_stage' => 'full',
            'amount' => 95,
            'currency' => 'USD',
        ]);
        $this->assertDatabaseHas('payment_status_histories', [
            'status' => 'pending',
            'changed_by_type' => 'user',
            'changed_by_id' => $user->id,
        ]);
    }

    public function test_full_before_service_order_rejects_deposit_stage(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($order, stage: 'deposit'))
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'This order requires full payment before service.');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_deposit_policy_order_accepts_the_deposit_stage_with_the_snapshot_amount(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile, paymentType: ServicePaymentType::Deposit);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($order, stage: 'deposit'));

        $response
            ->assertCreated()
            ->assertJsonPath('data.payment_stage', 'deposit')
            ->assertJsonPath('data.amount', '28.50');
    }

    public function test_deposit_policy_order_rejects_the_full_stage(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile, paymentType: ServicePaymentType::Deposit);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($order))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This order uses the deposit then balance payment sequence.');
    }

    public function test_balance_stage_requires_a_paid_deposit(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile, paymentType: ServicePaymentType::Deposit);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($order, stage: 'balance'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The deposit payment must be confirmed before the remaining balance.');
    }

    public function test_balance_stage_requires_service_completion(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile, paymentType: ServicePaymentType::Deposit);
        $this->createPaidPayment($profile, $order, PaymentStage::Deposit, '28.50');

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($order, stage: 'balance'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This payment becomes payable after service completion.');
    }

    public function test_balance_stage_uses_the_remaining_amount_snapshot_after_completion(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile, paymentType: ServicePaymentType::Deposit);
        $this->createPaidPayment($profile, $order, PaymentStage::Deposit, '28.50');
        $order->quotation->booking->update(['status' => BookingStatus::Completed]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($order, stage: 'balance'));

        $response
            ->assertCreated()
            ->assertJsonPath('data.payment_stage', 'balance')
            ->assertJsonPath('data.amount', '66.50');
    }

    public function test_pay_after_service_order_is_payable_only_after_completion(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile, paymentType: ServicePaymentType::PayAfterService);
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $this
            ->withToken($token)
            ->postJson('/api/v1/payments/initialize', $this->payload($order))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This payment becomes payable after service completion.');

        $order->quotation->booking->update(['status' => BookingStatus::Completed]);

        $this
            ->withToken($token)
            ->postJson('/api/v1/payments/initialize', $this->payload($order, idempotencyKey: 'idem-after'))
            ->assertCreated()
            ->assertJsonPath('data.payment_stage', 'full')
            ->assertJsonPath('data.amount', '95.00');
    }

    public function test_cash_on_delivery_is_rejected_for_service_orders(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($order, method: 'cash_on_delivery'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cash on delivery is available for store orders only.');
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
            ->assertJsonStructure(['errors' => [
                'payable_type',
                'payable_id',
                'payment_method',
                'payment_stage',
                'idempotency_key',
            ]]);
    }

    public function test_payment_initialization_rejects_removed_v1_methods(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($order, method: 'jeeb'))
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['payment_method']]);
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

        $firstResponse = $this->withToken($token)
            ->postJson('/api/v1/payments/initialize', $this->payload($order, idempotencyKey: 'idem-1'));
        $secondResponse = $this->withToken($token)
            ->postJson('/api/v1/payments/initialize', $this->payload($order, idempotencyKey: 'idem-2'));

        $firstResponse->assertCreated();
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.payment_number', $firstResponse->json('data.payment_number'));

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_status_histories', 1);
    }

    public function test_payment_initialization_replays_the_same_idempotency_key(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile);
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $firstResponse = $this->withToken($token)
            ->postJson('/api/v1/payments/initialize', $this->payload($order, idempotencyKey: 'idem-same'));
        $secondResponse = $this->withToken($token)
            ->postJson('/api/v1/payments/initialize', $this->payload($order, idempotencyKey: 'idem-same'));

        $firstResponse->assertCreated();
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.payment_number', $firstResponse->json('data.payment_number'));

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_an_idempotency_key_cannot_be_reused_by_another_customer(): void
    {
        $ownerUser = User::factory()->create();
        $ownerProfile = CustomerProfile::factory()->create(['user_id' => $ownerUser->id]);
        $ownerOrder = $this->createOrder($ownerProfile);

        $this->withToken($ownerUser->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($ownerOrder, idempotencyKey: 'idem-shared'))
            ->assertCreated();

        $otherUser = User::factory()->create();
        $otherProfile = CustomerProfile::factory()->create(['user_id' => $otherUser->id]);
        $otherOrder = $this->createOrder($otherProfile);

        $this->app['auth']->forgetGuards();

        $this->withToken($otherUser->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/payments/initialize', $this->payload($otherOrder, idempotencyKey: 'idem-shared'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The idempotency key has already been used.');
    }

    public function test_customer_can_initialize_a_new_payment_after_a_failed_payment(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile);
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $firstResponse = $this->withToken($token)
            ->postJson('/api/v1/payments/initialize', $this->payload($order, idempotencyKey: 'idem-1'));
        $firstResponse->assertCreated();

        Payment::query()
            ->where('payment_number', $firstResponse->json('data.payment_number'))
            ->update(['status' => 'failed']);

        $secondResponse = $this->withToken($token)
            ->postJson('/api/v1/payments/initialize', $this->payload($order, idempotencyKey: 'idem-2'));

        $secondResponse
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseCount('payments', 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        Order $order,
        string $method = 'evc_plus',
        string $stage = 'full',
        string $idempotencyKey = 'idem-001',
    ): array {
        return [
            'payable_type' => 'order',
            'payable_id' => $order->id,
            'payment_method' => $method,
            'payment_stage' => $stage,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    private function createPaidPayment(
        CustomerProfile $profile,
        Order $order,
        PaymentStage $stage,
        string $amount,
    ): Payment {
        return Payment::query()->create([
            'payment_number' => sprintf('PAY-%s-%06d', now()->format('Y'), 900000 + $this->sequence++),
            'customer_profile_id' => $profile->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'status' => PaymentStatus::Paid,
            'payment_method' => 'evc_plus',
            'payment_stage' => $stage,
            'amount' => $amount,
            'currency' => 'USD',
            'paid_at' => now(),
            'receipt_number' => sprintf('RCPT-%s-%06d', now()->format('Y'), 900000 + $this->sequence),
        ]);
    }

    private function createOrder(
        CustomerProfile $profile,
        OrderStatus $status = OrderStatus::PendingPayment,
        ServicePaymentType $paymentType = ServicePaymentType::FullBeforeService,
    ): Order {
        $booking = $this->createBooking($profile, $paymentType);

        $quotation = Quotation::query()->create([
            'quotation_number' => sprintf('QT-%s-%06d', now()->format('Y'), $this->sequence++),
            'customer_profile_id' => $profile->id,
            'booking_id' => $booking->id,
            'status' => QuotationStatus::Accepted,
            'currency' => 'USD',
            'subtotal' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '5.00',
            'total_amount' => '95.00',
            'payment_type' => $paymentType,
            'deposit_percentage' => $paymentType === ServicePaymentType::Deposit ? 30 : null,
            'deposit_amount' => $paymentType === ServicePaymentType::Deposit ? '28.50' : null,
            'remaining_amount' => match ($paymentType) {
                ServicePaymentType::Deposit => '66.50',
                ServicePaymentType::PayAfterService => '95.00',
                default => null,
            },
            'valid_until' => now()->addWeek(),
            'accepted_at' => now(),
        ]);

        return Order::query()->create([
            'order_number' => sprintf('ORD-%s-%06d', now()->format('Y'), $this->sequence),
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

    private function createBooking(
        CustomerProfile $profile,
        ServicePaymentType $paymentType,
    ): Booking {
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
            'payment_type' => $paymentType,
            'deposit_percentage' => $paymentType === ServicePaymentType::Deposit ? 30 : null,
        ]);
        $mode = ServiceModeOption::query()->create([
            'service_id' => $service->id,
            'mode' => ServiceMode::OneTime,
            'is_active' => true,
        ]);

        return Booking::query()->create([
            'booking_number' => sprintf('BK-%s-%06d', now()->format('Y'), $this->sequence),
            'customer_profile_id' => $profile->id,
            'service_id' => $service->id,
            'service_mode_id' => $mode->id,
            'status' => BookingStatus::Accepted,
            'requested_date' => now()->addWeek()->toDateString(),
            'requested_time_window' => '09:00-12:00',
            'address_snapshot' => [
                'line1' => 'KM4 Road',
                'city' => 'Mogadishu',
                'country_code' => 'SO',
            ],
        ]);
    }
}
