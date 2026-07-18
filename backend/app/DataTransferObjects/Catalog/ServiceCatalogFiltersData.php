<?php

namespace App\DataTransferObjects\Catalog;

use App\Enums\ServiceMode;

final readonly class ServiceCatalogFiltersData
{
    public function __construct(
        public ?int $categoryId = null,
        public ?ServiceMode $mode = null,
        public ?string $city = null,
        public string $sort = 'display_order',
        public int $perPage = 20,
    ) {}
}
