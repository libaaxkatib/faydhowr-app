<?php

namespace App\DataTransferObjects\Quotation;

use App\Enums\QuotationStatus;

final readonly class AdminQuotationFiltersData
{
    public function __construct(
        public ?QuotationStatus $status,
        public ?int $assignedAdminId,
        public ?int $customerProfileId,
        public ?string $from,
        public ?string $to,
        public ?string $search,
        public int $perPage,
    ) {}
}
