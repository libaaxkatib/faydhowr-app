<?php

namespace App\Services\Search;

use App\Contracts\Catalog\Repositories\ProductCatalogRepositoryInterface;
use App\Contracts\Catalog\Repositories\ServiceCatalogRepositoryInterface;
use App\Contracts\Search\Services\GlobalSearchServiceInterface;
use App\DataTransferObjects\Search\SearchQueryData;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Service;
use App\Support\Catalog\ServiceImages;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GlobalSearchService implements GlobalSearchServiceInterface
{
    public const SUGGESTION_LIMIT = 10;

    private const MIN_QUERY_LENGTH = 2;

    public function __construct(
        private ServiceCatalogRepositoryInterface $serviceCatalog,
        private ProductCatalogRepositoryInterface $productCatalog,
    ) {}

    public function search(SearchQueryData $data): array
    {
        return [
            'services' => $data->type->includesServices()
                ? $this->serviceCatalog->searchVisible($data->query, $data->perPage)
                : null,
            'products' => $data->type->includesProducts()
                ? $this->productCatalog->searchVisible($data->query, null, $data->perPage)
                : null,
        ];
    }

    public function searchProducts(string $query, ?int $categoryId, int $perPage): LengthAwarePaginator
    {
        return $this->productCatalog->searchVisible($query, $categoryId, $perPage);
    }

    public function suggestions(?string $query): array
    {
        $query = trim((string) $query);

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        $services = $this->serviceCatalog
            ->suggestVisible($query, self::SUGGESTION_LIMIT)
            ->map(fn (Service $service): array => [
                'type' => 'service',
                'name' => $service->name,
                'slug' => $service->slug,
                'thumbnail' => ServiceImages::for($service)['thumbnail'],
            ]);

        $products = $this->productCatalog
            ->suggestVisible($query, self::SUGGESTION_LIMIT)
            ->map(fn (Product $product): array => [
                'type' => 'product',
                'name' => $product->name,
                'slug' => $product->slug,
                'thumbnail' => $this->productThumbnail($product),
            ]);

        return $services
            ->concat($products)
            ->sortBy([
                fn (array $a, array $b): int => $this->matchTier($a['name'], $query) <=> $this->matchTier($b['name'], $query),
                fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']),
            ])
            ->take(self::SUGGESTION_LIMIT)
            ->values()
            ->all();
    }

    /**
     * Match tier per API Design §15.5 so the combined service + product
     * suggestion list keeps the ranked order across both sources.
     */
    private function matchTier(string $name, string $query): int
    {
        $name = mb_strtolower($name);
        $query = mb_strtolower($query);

        return match (true) {
            $name === $query => 1,
            str_starts_with($name, $query) => 2,
            str_contains($name, ' '.$query) => 3,
            default => 4,
        };
    }

    private function productThumbnail(Product $product): ?string
    {
        /** @var ProductImage|null $primary */
        $primary = $product->images->firstWhere('is_primary', true) ?? $product->images->first();

        return $primary?->url();
    }
}
