<?php

namespace App\DataTransferObjects\Review;

final readonly class UpdateReviewData
{
    public function __construct(
        public int $rating,
        public ?string $title,
        public ?string $comment,
    ) {}
}
