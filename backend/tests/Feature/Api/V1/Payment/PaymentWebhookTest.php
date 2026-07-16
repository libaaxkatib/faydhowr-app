<?php

namespace Tests\Feature\Api\V1\Payment;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Events\Payment\PaymentFailed;
use App\Events\Payment\PaymentPaid;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    public function test_webhook_can_mark_a_processing_payment_as_paid_and_confirm_the_order(): void
    {
        Event::fake([PaymentPaid::class]);

        $payment = $this->createProcessingPayment('TXN-PAID-001');
        $payload = $this->webhookPayload('TXN-PAID-001', 'success');

        $response = $this->postWebhook($payload);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Payment webhook processed successfully.')
            ->assertJsonPath('data.payment_number', $payment->payment_number)
            ->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $payment->id,
            'transaction_reference' => 'TXN-PAID-001',
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('payment_status_histories', [
            'payment_id' => $payment->id,
            'status' => 'paid',
            'changed_by_type' => 'system',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $payment->payable_id,
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $payment->payable_id,
            'status' => 'confirmed',
            'changed_by_type' => 'system',
        ]);

        Event::assertDispatched(PaymentPaid::class, fn (PaymentPaid $event): bool => $event->payment->is($payment));
    }

    public function test_webhook_can_mark_a_processing_payment_as_failed_without_changing_the_order(): void
    {
        Event::fake([PaymentFailed::class]);

        $payment = $this->createProcessingPayment('TXN-FAILED-001');
        $payload = $this->webhookPayload('TXN-FAILED-001', 'failed');

        $response = $this->postWebhook($payload);

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $payment->id,
            'transaction_reference' => 'TXN-FAILED-001',
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('payment_status_histories', [
            'payment_id' => $payment->id,
            'status' => 'failed',
            'changed_by_type' => 'system',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $payment->payable_id,
            'status' => 'pending_payment',
        ]);
        $this->assertDatabaseCount('order_status_histories', 0);

        Event::assertDispatched(PaymentFailed::class, fn (PaymentFailed $event): bool => $event->payment->is($payment));
    }

    public function test_webhook_rejects_an_invalid_signature(): void
    {
        $this->createProcessingPayment('TXN-INVALID-SIG');
        $payload = $this->webhookPayload('TXN-INVALID-SIG', 'success');

        $this
            ->withHeader('X-Payment-Signature', 'invalid-signature')
            ->postJson('/api/v1/payments/webhook', $payload)
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'INVALID_WEBHOOK_SIGNATURE');

        $this->assertDatabaseHas('payments', [
            'status' => 'processing',
        ]);
    }

    public function test_duplicate_successful_callbacks_are_idempotent(): void
    {
        Event::fake([PaymentPaid::class]);

        $payment = $this->createProcessingPayment('TXN-DUPLICATE-001');
        $payload = $this->webhookPayload('TXN-DUPLICATE-001', 'success');

        $firstResponse = $this->postWebhook($payload);
        $secondResponse = $this->postWebhook($payload);

        $firstResponse->assertOk();
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseCount('payment_status_histories', 1);
        $this->assertDatabaseCount('order_status_histories', 1);

        Event::assertDispatchedTimes(PaymentPaid::class, 1);
    }

    public function test_webhook_returns_not_found_for_unknown_transaction_reference(): void
    {
        $payload = $this->webhookPayload('TXN-UNKNOWN', 'success');

        $this
            ->postWebhook($payload)
            ->assertNotFound()
            ->assertJsonPath('error_code', 'PAYMENT_NOT_FOUND');
    }

    public function test_webhook_rejects_terminal_payments(): void
    {
        $payment = $this->createProcessingPayment('TXN-TERMINAL-001');
        $payment->update(['status' => PaymentStatus::Failed]);

        $this
            ->postWebhook($this->webhookPayload('TXN-TERMINAL-001', 'success'))
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'Payment is already in a terminal state.');
    }

    public function test_webhook_rejects_an_invalid_status_payload(): void
    {
        $this->createProcessingPayment('TXN-INVALID-STATUS');

        $payload = [
            'gateway' => 'manual',
            'transaction_reference' => 'TXN-INVALID-STATUS',
            'status' => 'unknown',
        ];

        $this
            ->withHeader('X-Payment-Signature', $this->signPayload($payload))
            ->postJson('/api/v1/payments/webhook', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_duplicate_failed_callbacks_are_idempotent(): void
    {
        Event::fake([PaymentFailed::class]);

        $payment = $this->createProcessingPayment('TXN-DUPLICATE-FAILED');
        $payload = $this->webhookPayload('TXN-DUPLICATE-FAILED', 'failed');

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $this->assertDatabaseCount('payment_status_histories', 1);
        Event::assertDispatchedTimes(PaymentFailed::class, 1);
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function postWebhook(array $payload)
    {
        return $this
            ->withHeader('X-Payment-Signature', $this->signPayload($payload))
            ->postJson('/api/v1/payments/webhook', $payload);
    }

    /**
     * @return array{gateway: string, transaction_reference: string, status: string}
     */
    private function webhookPayload(string $transactionReference, string $status): array
    {
        return [
            'gateway' => 'manual',
            'transaction_reference' => $transactionReference,
            'status' => $status,
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function signPayload(array $payload): string
    {
        $rawPayload = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return hash_hmac(
            'sha256',
            $rawPayload,
            (string) config('payments.gateways.manual.webhook_secret'),
        );
    }

    private function createProcessingPayment(string $transactionReference): Payment
    {
        $profile = CustomerProfile::factory()->create();
        $order = $this->createOrder($profile);

        $payment = Payment::query()->create([
            'payment_number' => sprintf('PAY-%s-%06d', now()->format('Y'), $this->sequence),
            'customer_profile_id' => $profile->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'status' => PaymentStatus::Processing,
            'amount' => $order->total_amount,
            'currency' => $order->currency,
            'gateway' => 'manual',
            'gateway_reference' => $transactionReference,
        ]);

        $payment->transactions()->create([
            'gateway' => 'manual',
            'transaction_reference' => $transactionReference,
            'status' => PaymentStatus::Processing->value,
            'processed_at' => now(),
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
