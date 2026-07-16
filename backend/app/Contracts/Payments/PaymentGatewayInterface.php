<?php

namespace App\Contracts\Payments;

use App\Models\Payment;
use App\Models\PaymentTransaction;

interface PaymentGatewayInterface
{
    /**
     * Create a provider-specific payment request in a future gateway adapter.
     *
     * @return array<string, mixed>
     */
    public function initiate(Payment $payment): array;

    /**
     * Verify a provider-specific result in a future gateway adapter.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function verify(PaymentTransaction $transaction, array $payload): array;

    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool;
}
