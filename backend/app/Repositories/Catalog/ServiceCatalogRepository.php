<?php

namespace App\Repositories\Catalog;

use App\Contracts\Catalog\Repositories\ServiceCatalogRepositoryInterface;
use App\DataTransferObjects\Catalog\ServiceCatalogFiltersData;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ServiceCatalogRepository implements ServiceCatalogRepositoryInterface
{
    public function paginateVisible(ServiceCatalogFiltersData $filters): LengthAwarePaginator
    {
        $query = $this->visibleQuery();

        if ($filters->categoryId !== null) {
            $query->where('category_id', $filters->categoryId);
        }

        if ($filters->mode !== null) {
            $query->whereHas('modes', function (Builder $modes) use ($filters): void {
                $modes->where('mode', $filters->mode->value)->where('is_active', true);
            });
        }

        if ($filters->city !== null) {
            $query->whereHas('coverageCities', function (Builder $cities) use ($filters): void {
                $cities->where('city', $filters->city)->where('is_active', true);
            });
        }

        $this->applySort($query, $filters->sort);

        return $query->paginate($filters->perPage);
    }

    public function findVisibleBySlug(string $slug): ?Service
    {
        return $this->visibleQuery()
            ->where('slug', $slug)
            ->first();
    }

    public function searchVisible(string $query, int $perPage): LengthAwarePaginator
    {
        $builder = $this->visibleQuery()
            ->where(function (Builder $search) use ($query): void {
                $like = '%'.addcslashes($query, '%_\\').'%';
                $search->where('name', 'like', $like)
                    ->orWhere('short_description', 'like', $like);
            });

        $this->applySort($builder, 'display_order');

        return $builder->paginate($perPage);
    }

    public function categoriesWithVisibleServices(): Collection
    {
        return ServiceCategory::query()
            ->where('is_active', true)
            ->whereHas('services', function (Builder $services): void {
                $services->where('is_active', true)
                    ->whereHas('modes', fn (Builder $modes) => $modes->where('is_active', true));
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Visibility per FR-010A / API §6.1: active, non-soft-deleted services
     * with at least one active mode, eager-loaded for card/detail payloads.
     *
     * @return Builder<Service>
     */
    private function visibleQuery(): Builder
    {
        return Service::query()
            ->where('is_active', true)
            ->whereHas('modes', fn (Builder $modes) => $modes->where('is_active', true))
            ->with([
                'modes' => fn ($modes) => $modes->where('is_active', true)->orderBy('id'),
                'coverageCities' => fn ($cities) => $cities->where('is_active', true)->orderBy('city'),
                'media' => fn ($media) => $media->orderBy('sort_order')->orderBy('id'),
            ]);
    }

    /**
     * @param  Builder<Service>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        if ($sort === 'name') {
            $query->orderBy('name')->orderBy('id');

            return;
        }

        $query->orderBy('sort_order')->orderBy('id');
    }
}
