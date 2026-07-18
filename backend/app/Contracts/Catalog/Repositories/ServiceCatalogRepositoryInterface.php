<?php

namespace App\Contracts\Catalog\Repositories;

use App\DataTransferObjects\Catalog\ServiceCatalogFiltersData;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ServiceCatalogRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, Service>
     */
    public function paginateVisible(ServiceCatalogFiltersData $filters): LengthAwarePaginator;

    public function findVisibleBySlug(string $slug): ?Service;

    /**
     * @return LengthAwarePaginator<int, Service>
     */
    public function searchVisible(string $query, int $perPage): LengthAwarePaginator;

    /**
     * @return Collection<int, ServiceCategory>
     */
    public function categoriesWithVisibleServices(): Collection;
}
