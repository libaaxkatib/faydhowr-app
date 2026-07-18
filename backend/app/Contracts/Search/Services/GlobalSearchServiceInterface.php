<?php

namespace App\Contracts\Search\Services;

use App\DataTransferObjects\Search\SearchQueryData;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface GlobalSearchServiceInterface
{
    /**
     * Unified search (API Design §15.3): grouped, ranked results. A group
     * excluded by the requested type is null.
     *
     * @return array{services: LengthAwarePaginator<int, Service>|null, products: LengthAwarePaginator<int, Product>|null}
     */
    public function search(SearchQueryData $data): array;

    /**
     * Ranked product search (API Design §15.2).
     *
     * @return LengthAwarePaginator<int, Product>
     */
    public function searchProducts(string $query, ?int $categoryId, int $perPage): LengthAwarePaginator;

    /**
     * Search suggestions (API Design §15.4): at most 10 combined results
     * with only type, name, slug, and thumbnail — never prices, discounts,
     * or stock. Queries shorter than 2 characters yield an empty list.
     *
     * @return list<array{type: string, name: string, slug: string, thumbnail: ?string}>
     */
    public function suggestions(?string $query): array;
}
