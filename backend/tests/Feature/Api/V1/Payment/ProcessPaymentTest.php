<?php

namespace Tests\Feature\Api\V1\Payment;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessPaymentTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    public function test_customer_can_process_an_initialized_payment(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $payment = $this->createPayment($profile);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/payments/{$payment->id}/process");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Payment processing started successfully.')
            ->assertJsonPath('meta', null)
            ->assertJsonPath('data.payment_number', $payment->payment_number)
            ->assertJsonPath('data.status', 'processing');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'processing',
        ]);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $payment->id,
            'status' => 'processing',
        ]);
        $this->assertDatabaseHas('payment_status_histories', [
            'payment_id' => $payment->id,
            'status' => 'processing',
            'changed_by_type' => 'user',
            'changed_by_id' => $user->id,
        ]);
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_customer_cannot_process_a_payment_in_an_invalid_status(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $payment = $this->createPayment($profile, PaymentStatus::Paid);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/payments/{$payment->id}/process")
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'Only initialized payments can be processed.');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);
    }

    public function test_customer_cannot_process_another_customers_payment(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);
        $payment = $this->createPayment(CustomerProfile::factory()->create());

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/payments/{$payment->id}/process")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'PAYMENT_NOT_FOUND');
    }

    public function test_payment_processing_requires_a_customer_profile(): void
    {
        $user = User::factory()->create();
        $payment = $this->createPayment(CustomerProfile::factory()->create());

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/payments/{$payment->id}/process")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_PROFILE_NOT_FOUND');
    }

    public function test_payment_processing_requires_authentication(): void
    {
        $payment = $this->createPayment(CustomerProfile::factory()->create());

        $this
            ->postJson("/api/v1/payments/{$payment->id}/process")
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_customer_cannot_process_a_payment_that_is_already_processing(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $payment = $this->createPayment($profile, PaymentStatus::Processing);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/payments/{$payment->id}/process")
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'Only initialized payments can be processed.');
    }

    private function createPayment(
        CustomerProfile $profile,
        PaymentStatus $status = PaymentStatus::Initialized,
    ): Payment {
        $order = $this->createOrder($profile);
        $payment = Payment::query()->create([
            'payment_number' => sprintf('PAY-%s-%06d', now()->format('Y'), $this->sequence),
            'customer_profile_id' => $profile->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'status' => $status,
            'amount' => $order->total_amount,
            'currency' => $order->currency,
            'gateway' => 'manual',
        ]);

        $payment->transactions()->create([
            'gateway' => 'manual',
            'status' => $status->value,
        ]);

        return $payment;
    }

    private function createOrder(CustomerProfile $profile): Order
    {
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

        return Order::query()->create([
            'order_number' => sprintf('ORD-%s-%06d', now()->format('Y'), $this->sequence),
            'customer_profile_id' => $profile->id,
            'quotation_id' => $quotation->id,
            'status' => OrderStatus::PendingPayment,
            'currency' => $quotation->currency,
            'subtotal' => $quotation->subtotal,
            'discount_amount' => $quotation->discount_amount,
            'tax_amount' => $quotation->tax_amount,
            'total_amount' => $quotation->total_amount,
        ]);
    }
}
