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

class CancelOrderTest extends TestCase
{
    use RefreshDatabase;

    private int $orderSequence = 1;

    private int $quotationSequence = 1;

    public function test_customer_can_cancel_a_pending_payment_order(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile, OrderStatus::PendingPayment);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/orders/{$order->id}/cancel", [
                'cancellation_reason' => 'Order is no longer needed.',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Order cancelled successfully.')
            ->assertJsonPath('meta', null)
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Order is no longer needed.');

        self::assertNotNull($response->json('data.cancelled_at'));
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
            'cancellation_reason' => 'Order is no longer needed.',
        ]);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'status' => 'cancelled',
            'changed_by_type' => 'user',
            'changed_by_id' => $user->id,
            'notes' => 'Order is no longer needed.',
        ]);
    }

    public function test_customer_cannot_cancel_an_already_cancelled_order(): void
    {
        $this->assertCancellationIsRejected(OrderStatus::Cancelled);
    }

    public function test_customer_can_cancel_a_pending_payment_order_without_a_reason(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/orders/{$order->id}/cancel");

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', null);
    }

    public function test_order_cancellation_rejects_an_oversized_reason(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/orders/{$order->id}/cancel", [
                'cancellation_reason' => str_repeat('a', 256),
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['cancellation_reason']]);
    }

    public function test_customer_cannot_cancel_a_confirmed_order(): void
    {
        $this->assertCancellationIsRejected(OrderStatus::Confirmed);
    }

    public function test_customer_cannot_cancel_a_processing_order(): void
    {
        $this->assertCancellationIsRejected(OrderStatus::Processing);
    }

    public function test_customer_cannot_cancel_a_completed_order(): void
    {
        $this->assertCancellationIsRejected(OrderStatus::Completed);
    }

    public function test_non_owned_order_cancellation_returns_not_found(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder(CustomerProfile::factory()->create());

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/orders/{$order->id}/cancel");

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'ORDER_NOT_FOUND');
    }

    public function test_order_cancellation_returns_not_found_when_customer_profile_is_missing(): void
    {
        $user = User::factory()->create();
        $order = $this->createOrder(CustomerProfile::factory()->create());

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/orders/{$order->id}/cancel");

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_PROFILE_NOT_FOUND');
    }

    public function test_order_cancellation_requires_authentication(): void
    {
        $order = $this->createOrder(CustomerProfile::factory()->create());

        $this
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    private function assertCancellationIsRejected(OrderStatus $status): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        $order = $this->createOrder($profile, $status);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/orders/{$order->id}/cancel");

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'This order cannot be cancelled.');
    }

    private function createOrder(
        CustomerProfile $profile,
        OrderStatus $status = OrderStatus::PendingPayment,
    ): Order {
        $quotation = Quotation::query()->create([
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

        return Order::query()->create([
            'order_number' => sprintf('ORD-%s-%06d', now()->format('Y'), $this->orderSequence++),
            'customer_profile_id' => $profile->id,
            'quotation_id' => $quotation->id,
            'status' => $status,
            'subtotal' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '5.00',
            'total_amount' => '95.00',
            'notes' => 'Order notes.',
            'cancelled_at' => $status === OrderStatus::Cancelled ? now() : null,
        ]);
    }
}
