<?php

namespace App\DataTransferObjects\Review;

use App\Enums\Review\ReviewStatus;

final readonly class AdminReviewFiltersData
{
    public function __construct(
        public ?ReviewStatus $status,
        public ?int $serviceId,
        public ?int $rating,
        public ?string $from,
        public ?string $to,
        public int $perPage,
    ) {}
}
