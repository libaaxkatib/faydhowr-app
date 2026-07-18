<?php

namespace App\DataTransferObjects\Review;

final readonly class CreateReviewData
{
    public function __construct(
        public int $customerProfileId,
        public int $bookingId,
        public int $serviceId,
        public int $rating,
        public ?string $title,
        public ?string $comment,
    ) {}
}
