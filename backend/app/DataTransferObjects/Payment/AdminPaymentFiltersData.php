<?php

namespace App\DataTransferObjects\Payment;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStage;
use App\Enums\PaymentStatus;

final readonly class AdminPaymentFiltersData
{
    public function __construct(
        public ?PaymentStatus $status,
        public ?PaymentMethod $paymentMethod,
        public ?PaymentStage $paymentStage,
        public ?string $payableType,
        public ?int $customerProfileId,
        public ?string $from,
        public ?string $to,
        public ?string $search,
        public int $perPage,
    ) {}
}
