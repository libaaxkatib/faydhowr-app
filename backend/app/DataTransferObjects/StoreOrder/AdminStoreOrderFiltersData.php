<?php

namespace App\DataTransferObjects\StoreOrder;

use App\Enums\PaymentStatus;
use App\Enums\StoreOrderStatus;

final readonly class AdminStoreOrderFiltersData
{
    public function __construct(
        public ?StoreOrderStatus $status,
        public ?PaymentStatus $paymentStatus,
        public ?int $customerProfileId,
        public ?string $from,
        public ?string $to,
        public ?string $search,
        public int $perPage,
    ) {}
}
