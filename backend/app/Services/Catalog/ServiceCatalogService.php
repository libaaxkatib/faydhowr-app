<?php

namespace App\Services\Catalog;

use App\Contracts\Catalog\Repositories\ServiceCatalogRepositoryInterface;
use App\Contracts\Catalog\Services\ServiceCatalogServiceInterface;
use App\DataTransferObjects\Catalog\ServiceCatalogFiltersData;
use App\Models\Service;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ServiceCatalogService implements ServiceCatalogServiceInterface
{
    public function __construct(private ServiceCatalogRepositoryInterface $catalog) {}

    public function listServices(ServiceCatalogFiltersData $filters): LengthAwarePaginator
    {
        return $this->catalog->paginateVisible($filters);
    }

    public function getServiceBySlug(string $slug): ?Service
    {
        return $this->catalog->findVisibleBySlug($slug);
    }

    public function searchServices(string $query, int $perPage): LengthAwarePaginator
    {
        return $this->catalog->searchVisible($query, $perPage);
    }

    public function listCategories(): Collection
    {
        return $this->catalog->categoriesWithVisibleServices();
    }
}
