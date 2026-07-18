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
     * Ranked service search over name and short description
     * (API Design §6.4, §15.5).
     *
     * @return LengthAwarePaginator<int, Service>
     */
    public function searchVisible(string $query, int $perPage): LengthAwarePaginator;

    /**
     * @return Collection<int, ServiceCategory>
     */
    public function categoriesWithVisibleServices(): Collection;

    /**
     * Home featured row: visible services flagged is_featured, ordered by
     * sort_order (API Design §5.1). Inactive services never appear.
     *
     * @return Collection<int, Service>
     */
    public function featuredVisible(int $limit): Collection;

    /**
     * Home popular row: visible services ranked internally by
     * favorites_count; the count itself is never serialized (§5.1).
     *
     * @return Collection<int, Service>
     */
    public function popularVisible(int $limit): Collection;

    /**
     * Ranked minimal matches for search suggestions (API Design §15.4).
     *
     * @return Collection<int, Service>
     */
    public function suggestVisible(string $query, int $limit): Collection;
}
