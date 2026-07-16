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

class PaymentRetrievalTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    public function test_customer_can_list_only_their_payments_newest_first(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $olderPayment = $this->createPayment($profile, PaymentStatus::Initialized, now()->subDay());
        $newerPayment = $this->createPayment($profile, PaymentStatus::Paid, now());
        $this->createPayment(CustomerProfile::factory()->create());

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/payments');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Payments retrieved successfully.')
            ->assertJsonPath('meta', null)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.payment_number', $newerPayment->payment_number)
            ->assertJsonPath('data.items.1.payment_number', $olderPayment->payment_number);
    }

    public function test_customer_payments_are_paginated(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);

        $this->createPayment($profile);
        $this->createPayment($profile);
        $this->createPayment($profile);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/payments?per_page=2&page=2');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.last_page', 2);
    }

    public function test_customer_can_filter_payments_by_status(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $this->createPayment($profile, PaymentStatus::Initialized);
        $paidPayment = $this->createPayment($profile, PaymentStatus::Paid);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/payments?status=paid');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.payment_number', $paidPayment->payment_number)
            ->assertJsonPath('data.items.0.status', 'paid');
    }

    public function test_customer_can_filter_payments_by_gateway(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $this->createPayment($profile, gateway: 'manual');
        $matchingPayment = $this->createPayment($profile, gateway: 'evc_plus');

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/payments?gateway=evc_plus');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.payment_number', $matchingPayment->payment_number)
            ->assertJsonPath('data.items.0.gateway', 'evc_plus');
    }

    public function test_customer_can_filter_payments_by_payable_type(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $matchingPayment = $this->createPayment($profile);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/payments?payable_type=order');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.payment_number', $matchingPayment->payment_number)
            ->assertJsonPath('data.items.0.payable.type', 'order');
    }

    public function test_customer_can_view_an_owned_payment(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $payment = $this->createPayment($profile, PaymentStatus::Paid, now(), 'RCPT-2026-000001');

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson("/api/v1/payments/{$payment->id}");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Payment retrieved successfully.')
            ->assertJsonPath('data.payment_number', $payment->payment_number)
            ->assertJsonPath('data.receipt_number', 'RCPT-2026-000001')
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.amount', '95.00')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.gateway', 'manual')
            ->assertJsonPath('data.payable.type', 'order')
            ->assertJsonPath('data.payable.order_number', $payment->payable->order_number)
            ->assertJsonPath('data.paid_at', $payment->paid_at->toISOString())
            ->assertJsonPath('data.created_at', $payment->created_at->toISOString())
            ->assertJsonMissingPath('data.customer_profile_id')
            ->assertJsonMissingPath('data.gateway_reference')
            ->assertJsonMissingPath('data.request_payload')
            ->assertJsonMissingPath('data.response_payload');
    }

    public function test_other_customers_payment_returns_not_found(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);
        $otherPayment = $this->createPayment(CustomerProfile::factory()->create());

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson("/api/v1/payments/{$otherPayment->id}");

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'PAYMENT_NOT_FOUND');
    }

    public function test_payment_retrieval_returns_not_found_when_customer_profile_is_missing(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/payments');

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_PROFILE_NOT_FOUND');
    }

    public function test_payment_retrieval_requires_authentication(): void
    {
        $this
            ->getJson('/api/v1/payments')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_payment_list_rejects_an_invalid_status_filter(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/payments?status=invalid')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_payment_list_rejects_an_invalid_payable_type_filter(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/payments?payable_type=invalid')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['payable_type']]);
    }

    private function createPayment(
        CustomerProfile $profile,
        PaymentStatus $status = PaymentStatus::Initialized,
        mixed $createdAt = null,
        ?string $receiptNumber = null,
        string $gateway = 'manual',
    ): Payment {
        $order = $this->createOrder($profile);
        $payment = Payment::query()->create([
            'payment_number' => sprintf('PAY-%s-%06d', now()->format('Y'), $this->sequence++),
            'receipt_number' => $receiptNumber,
            'customer_profile_id' => $profile->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'status' => $status,
            'amount' => $order->total_amount,
            'currency' => $order->currency,
            'gateway' => $gateway,
            'paid_at' => $status === PaymentStatus::Paid ? now() : null,
        ]);

        if ($createdAt !== null) {
            $payment->forceFill(['created_at' => $createdAt])->save();
        }

        return $payment->fresh('payable');
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
