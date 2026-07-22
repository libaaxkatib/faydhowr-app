<?php

namespace App\Actions\Product;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Support\Search\CatalogSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListProductsAction
{
    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function handle(
        ?int $categoryId,
        ?bool $featured,
        ?string $search,
        ?ProductStatus $status,
        bool $canFilterByStatus,
        int $perPage,
    ): LengthAwarePaginator {
        return Product::query()
            ->with(['category', 'images'])
            ->when(
                $canFilterByStatus && $status !== null,
                fn ($query) => $query->where('status', $status),
                fn ($query) => $query->where('status', ProductStatus::Active),
            )
            ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
            ->when($featured !== null, fn ($query) => $query->where('is_featured', $featured))
            ->when($search !== null && $search !== '', function ($query) use ($search): void {
                $term = '%'.CatalogSearch::escapeLike($search).'%';
                $query->where(function ($nested) use ($term): void {
                    $nested
                        ->where('sku', 'like', $term)
                        ->orWhere('name', 'like', $term);
                });
            })
            ->latest()
            ->paginate($perPage);
    }
}
