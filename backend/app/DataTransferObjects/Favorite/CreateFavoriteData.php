<?php

namespace App\DataTransferObjects\Favorite;

final readonly class CreateFavoriteData
{
    public function __construct(
        public int $customerProfileId,
        public int $serviceId,
    ) {}
}
