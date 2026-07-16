<?php

namespace Tests\Feature\Api\V1\Order;

use App\Enums\OrderStatus;
use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderRetrievalTest extends TestCase
{
    use RefreshDatabase;

    private int $orderSequence = 1;

    private int $quotationSequence = 1;

    public function test_customer_can_list_only_their_orders_newest_first(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $olderOrder = $this->createOrder($profile, OrderStatus::PendingPayment, now()->subDay());
        $newerOrder = $this->createOrder($profile, OrderStatus::Confirmed, now());
        $this->createOrder(CustomerProfile::factory()->create());

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/orders');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Orders retrieved successfully.')
            ->assertJsonPath('meta', null)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.order_number', $newerOrder->order_number)
            ->assertJsonPath('data.items.1.order_number', $olderOrder->order_number);
    }

    public function test_customer_orders_are_paginated(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);

        $this->createOrder($profile);
        $this->createOrder($profile);
        $this->createOrder($profile);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/orders?per_page=2&page=2');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.last_page', 2);
    }

    public function test_customer_can_filter_orders_by_status(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $this->createOrder($profile, OrderStatus::PendingPayment);
        $confirmedOrder = $this->createOrder($profile, OrderStatus::Confirmed);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/orders?status=confirmed');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.order_number', $confirmedOrder->order_number)
            ->assertJsonPath('data.items.0.status', 'confirmed');
    }

    public function test_customer_can_filter_orders_by_quotation(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $quotation = $this->createQuotation($profile);
        $matchingOrder = $this->createOrder($profile, OrderStatus::PendingPayment, null, $quotation);
        $this->createOrder($profile);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson("/api/v1/orders?quotation_id={$quotation->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.order_number', $matchingOrder->order_number)
            ->assertJsonPath('data.items.0.quotation.quotation_number', $quotation->quotation_number);
    }

    public function test_order_list_rejects_an_invalid_status_filter(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/orders?status=invalid');

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_order_list_rejects_a_non_integer_quotation_filter(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/orders?quotation_id=invalid');

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['quotation_id']]);
    }

    public function test_customer_can_view_an_owned_order(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile, OrderStatus::Confirmed);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson("/api/v1/orders/{$order->id}");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Order retrieved successfully.')
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.quotation.quotation_number', $order->quotation->quotation_number)
            ->assertJsonPath('data.subtotal', '100.00')
            ->assertJsonPath('data.discount_amount', '10.00')
            ->assertJsonPath('data.tax_amount', '5.00')
            ->assertJsonPath('data.total_amount', '95.00')
            ->assertJsonPath('data.notes', 'Order notes.')
            ->assertJsonPath('data.created_at', $order->created_at->toISOString())
            ->assertJsonMissingPath('data.customer_profile_id')
            ->assertJsonMissingPath('data.changed_by_id');
    }

    public function test_other_customers_order_returns_not_found(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);
        $otherOrder = $this->createOrder(CustomerProfile::factory()->create());

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson("/api/v1/orders/{$otherOrder->id}");

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'ORDER_NOT_FOUND');
    }

    public function test_order_retrieval_returns_not_found_when_customer_profile_is_missing(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/orders');

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_PROFILE_NOT_FOUND');
    }

    public function test_order_retrieval_requires_authentication(): void
    {
        $this
            ->getJson('/api/v1/orders')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    private function createOrder(
        CustomerProfile $profile,
        OrderStatus $status = OrderStatus::PendingPayment,
        mixed $createdAt = null,
        ?Quotation $quotation = null,
    ): Order {
        $quotation ??= $this->createQuotation($profile);
        $order = Order::query()->create([
            'order_number' => sprintf('ORD-%s-%06d', now()->format('Y'), $this->orderSequence++),
            'customer_profile_id' => $profile->id,
            'quotation_id' => $quotation->id,
            'status' => $status,
            'subtotal' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '5.00',
            'total_amount' => '95.00',
            'notes' => 'Order notes.',
        ]);

        if ($createdAt !== null) {
            $order->forceFill(['created_at' => $createdAt])->save();
        }

        return $order->refresh();
    }

    private function createQuotation(CustomerProfile $profile): Quotation
    {
        return Quotation::query()->create([
            'quotation_number' => sprintf('QT-%s-%06d', now()->format('Y'), $this->quotationSequence++),
            'customer_profile_id' => $profile->id,
            'status' => QuotationStatus::Accepted,
            'subtotal' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '5.00',
            'total_amount' => '95.00',
            'valid_until' => now()->addWeek(),
            'accepted_at' => now(),
        ]);
    }
}
