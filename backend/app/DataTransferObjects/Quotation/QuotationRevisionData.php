<?php

namespace App\DataTransferObjects\Quotation;

use DateTimeInterface;

final readonly class QuotationRevisionData
{
    public function __construct(
        public string $subtotal,
        public string $discountAmount,
        public string $taxAmount,
        public string $totalAmount,
        public DateTimeInterface $validUntil,
        public ?string $terms,
        public ?string $notes,
    ) {}
}
