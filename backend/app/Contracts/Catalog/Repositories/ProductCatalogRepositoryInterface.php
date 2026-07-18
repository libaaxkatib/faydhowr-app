<?php

namespace App\Contracts\Catalog\Repositories;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ProductCatalogRepositoryInterface
{
    /**
     * Home store products teaser: active products, featured first then
     * latest; out-of-stock products remain visible (API Design §5.1).
     *
     * @return Collection<int, Product>
     */
    public function featuredOrLatestVisible(int $limit): Collection;

    /**
     * Ranked product search over name and description (API Design §15.2).
     *
     * @return LengthAwarePaginator<int, Product>
     */
    public function searchVisible(string $query, ?int $categoryId, int $perPage): LengthAwarePaginator;

    /**
     * Ranked minimal matches for search suggestions (API Design §15.4).
     *
     * @return Collection<int, Product>
     */
    public function suggestVisible(string $query, int $limit): Collection;
}
