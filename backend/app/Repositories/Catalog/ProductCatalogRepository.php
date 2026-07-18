<?php

namespace App\Repositories\Catalog;

use App\Contracts\Catalog\Repositories\ProductCatalogRepositoryInterface;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Support\Search\CatalogSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProductCatalogRepository implements ProductCatalogRepositoryInterface
{
    public function featuredOrLatestVisible(int $limit): Collection
    {
        return $this->visibleQuery()
            ->orderByDesc('is_featured')
            ->latest()
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function searchVisible(string $query, ?int $categoryId, int $perPage): LengthAwarePaginator
    {
        $builder = CatalogSearch::filter($this->visibleQuery(), $query, 'name', 'description');

        if ($categoryId !== null) {
            $builder->where('category_id', $categoryId);
        }

        return CatalogSearch::rank($builder, $query, 'name', null, 'is_featured')
            ->paginate($perPage);
    }

    public function suggestVisible(string $query, int $limit): Collection
    {
        $builder = CatalogSearch::filter($this->visibleQuery(), $query, 'name', 'description');

        return CatalogSearch::rank($builder, $query, 'name', null, 'is_featured')
            ->limit($limit)
            ->get();
    }

    /**
     * Public visibility (API Design §15.2): active, non-deleted products;
     * out-of-stock products remain visible with their availability state.
     *
     * @return Builder<Product>
     */
    private function visibleQuery(): Builder
    {
        return Product::query()
            ->where('status', ProductStatus::Active)
            ->with(['category', 'images']);
    }
}
