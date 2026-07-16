<?php

namespace Tests\Feature\Api\V1\Payment;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Events\Payment\PaymentPaid;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PaymentReceiptNumberTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1;

    public function test_paid_webhook_generates_a_unique_receipt_number(): void
    {
        Event::fake([PaymentPaid::class]);

        $payment = $this->createProcessingPayment('TXN-RCPT-001');

        $response = $this->postWebhook($this->webhookPayload('TXN-RCPT-001', 'success'));

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $receiptNumber = $response->json('data.receipt_number');

        self::assertIsString($receiptNumber);
        self::assertMatchesRegularExpression(
            '/^RCPT-'.now()->format('Y').'-\d{6}$/',
            $receiptNumber,
        );

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Paid->value,
            'receipt_number' => $receiptNumber,
        ]);
    }

    public function test_duplicate_paid_webhook_does_not_regenerate_receipt_number(): void
    {
        Event::fake([PaymentPaid::class]);

        $payment = $this->createProcessingPayment('TXN-RCPT-DUP');
        $payload = $this->webhookPayload('TXN-RCPT-DUP', 'success');

        $firstResponse = $this->postWebhook($payload)->assertOk();
        $firstReceiptNumber = $firstResponse->json('data.receipt_number');

        $secondResponse = $this->postWebhook($payload)->assertOk();

        $secondResponse
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.receipt_number', $firstReceiptNumber);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'receipt_number' => $firstReceiptNumber,
        ]);
        $this->assertSame(
            1,
            Payment::query()->where('receipt_number', $firstReceiptNumber)->count(),
        );
        Event::assertDispatchedTimes(PaymentPaid::class, 1);
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
