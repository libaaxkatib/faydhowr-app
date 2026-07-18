<?php

namespace App\DataTransferObjects\Payment;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStage;

final readonly class InitializePaymentData
{
    public function __construct(
        public string $payableType,
        public int $payableId,
        public PaymentMethod $paymentMethod,
        public PaymentStage $paymentStage,
        public string $idempotencyKey,
    ) {}
}
