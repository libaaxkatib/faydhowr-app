<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\Payments\PaymentGatewayInterface;
use App\Models\Payment;
use App\Models\PaymentTransaction;

class ManualPaymentGateway implements PaymentGatewayInterface
{
    public function initiate(Payment $payment): array
    {
        return [
            'gateway' => $payment->gateway,
            'payment_number' => $payment->payment_number,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function verify(PaymentTransaction $transaction, array $payload): array
    {
        return $payload;
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        $secret = (string) config('payments.gateways.manual.webhook_secret', 'test-webhook-secret');
        $expected = hash_hmac('sha256', $rawPayload, $secret);

        return hash_equals($expected, $signature);
    }
}
