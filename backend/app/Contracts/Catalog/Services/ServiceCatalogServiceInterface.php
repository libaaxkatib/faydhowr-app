<?php

namespace App\Contracts\Catalog\Services;

use App\DataTransferObjects\Catalog\ServiceCatalogFiltersData;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ServiceCatalogServiceInterface
{
    /**
     * @return LengthAwarePaginator<int, Service>
     */
    public function listServices(ServiceCatalogFiltersData $filters): LengthAwarePaginator;

    public function getServiceBySlug(string $slug): ?Service;

    /**
     * @return LengthAwarePaginator<int, Service>
     */
    public function searchServices(string $query, int $perPage): LengthAwarePaginator;

    /**
     * @return Collection<int, ServiceCategory>
     */
    public function listCategories(): Collection;
}
