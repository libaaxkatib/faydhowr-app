<?php

namespace App\Services\Home;

use App\Contracts\Catalog\Repositories\ProductCatalogRepositoryInterface;
use App\Contracts\Catalog\Repositories\ServiceCatalogRepositoryInterface;
use App\Contracts\Home\HomeCacheInvalidatorInterface;
use App\Contracts\Home\Repositories\BeforeAfterItemRepositoryInterface;
use App\Contracts\Home\Repositories\FaqRepositoryInterface;
use App\Contracts\Home\Repositories\HeroBannerRepositoryInterface;
use App\Contracts\Home\Services\HomeServiceInterface;
use App\Contracts\Review\Repositories\ReviewRepositoryInterface;
use App\Contracts\Settings\Services\SettingsServiceInterface;
use App\DataTransferObjects\Settings\CompanySettingsData;
use App\Enums\Settings\SettingCategory;
use App\Http\Resources\Api\V1\Catalog\ServiceCardResource;
use App\Http\Resources\Api\V1\Catalog\ServiceCategoryResource;
use App\Http\Resources\Api\V1\Home\BeforeAfterItemResource;
use App\Http\Resources\Api\V1\Home\FaqResource;
use App\Http\Resources\Api\V1\Home\HeroBannerResource;
use App\Http\Resources\Api\V1\Product\ProductResource;
use App\Http\Resources\Api\V1\Reviews\PublicReviewResource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/**
 * Composes the guest Home payloads (API Design §5). Every payload is served
 * from a 5-minute cache; keys are recorded with the HomeCacheInvalidator so
 * content mutations flush exactly the Home entries (§18.1). Sections reuse
 * the existing catalog/product/review resources so the aggregate and section
 * endpoints stay byte-identical.
 */
class HomeService implements HomeServiceInterface
{
    public const CACHE_TTL_SECONDS = 300;

    public const VERSION = 'v1';

    private const FEATURED_SERVICES_LIMIT = 10;

    private const POPULAR_SERVICES_LIMIT = 10;

    private const STORE_PRODUCTS_LIMIT = 10;

    private const BEFORE_AFTER_TEASER_LIMIT = 10;

    private const REVIEWS_TEASER_LIMIT = 5;

    private const FAQ_TEASER_LIMIT = 5;

    public function __construct(
        private HeroBannerRepositoryInterface $heroBanners,
        private BeforeAfterItemRepositoryInterface $beforeAfterItems,
        private FaqRepositoryInterface $faqs,
        private ServiceCatalogRepositoryInterface $serviceCatalog,
        private ProductCatalogRepositoryInterface $productCatalog,
        private ReviewRepositoryInterface $reviews,
        private SettingsServiceInterface $settings,
        private HomeCacheInvalidatorInterface $cacheInvalidator,
    ) {}

    public function aggregate(): array
    {
        return $this->remember('home:aggregate', function (): array {
            $generatedAt = now();

            return [
                'sections' => [
                    'hero_banners' => $this->buildHeroBanners(),
                    'service_categories' => $this->buildServiceCategories(),
                    'featured_services' => $this->buildFeaturedServices(),
                    'popular_services' => $this->buildPopularServices(),
                    'featured_products' => $this->buildStoreProducts(),
                    'before_after' => $this->buildBeforeAfterTeasers(),
                    'reviews' => $this->buildReviewTeasers(),
                    'faq' => $this->buildFaqTeasers(),
                    'contact' => $this->buildContact(),
                ],
                'meta' => [
                    'generated_at' => $generatedAt->toISOString(),
                    'cache_expires_at' => $generatedAt->addSeconds(self::CACHE_TTL_SECONDS)->toISOString(),
                    'version' => self::VERSION,
                ],
            ];
        });
    }

    public function heroBanners(): array
    {
        return $this->remember('home:section:hero-banners', fn (): array => $this->buildHeroBanners());
    }

    public function serviceCategories(): array
    {
        return $this->remember('home:section:service-categories', fn (): array => $this->buildServiceCategories());
    }

    public function featuredServices(): array
    {
        return $this->remember('home:section:featured-services', fn (): array => $this->buildFeaturedServices());
    }

    public function storeProducts(): array
    {
        return $this->remember('home:section:store-products', fn (): array => $this->buildStoreProducts());
    }

    public function beforeAfter(int $page, int $perPage): array
    {
        return $this->remember(
            "home:section:before-after:{$page}:{$perPage}",
            function () use ($perPage): array {
                $paginator = $this->beforeAfterItems->paginateActive($perPage);

                return [
                    'items' => BeforeAfterItemResource::collection($paginator->items())->resolve(),
                    'meta' => $this->paginationMeta($paginator),
                ];
            },
        );
    }

    public function reviews(int $page, int $perPage): array
    {
        return $this->remember(
            "home:section:reviews:{$page}:{$perPage}",
            function () use ($perPage): array {
                $paginator = $this->reviews->paginatePublished($perPage);

                return [
                    'items' => PublicReviewResource::collection($paginator->items())->resolve(),
                    'meta' => $this->paginationMeta($paginator),
                ];
            },
        );
    }

    public function faqs(int $page, int $perPage): array
    {
        return $this->remember(
            "home:section:faq:{$page}:{$perPage}",
            function () use ($perPage): array {
                $paginator = $this->faqs->paginateActive($perPage);

                return [
                    'items' => FaqResource::collection($paginator->items())->resolve(),
                    'meta' => $this->paginationMeta($paginator),
                ];
            },
        );
    }

    public function contact(): array
    {
        return $this->remember('home:section:contact', fn (): array => $this->buildContact());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildHeroBanners(): array
    {
        return HeroBannerResource::collection($this->heroBanners->activeWithinSchedule())->resolve();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildServiceCategories(): array
    {
        return ServiceCategoryResource::collection($this->serviceCatalog->categoriesWithVisibleServices())->resolve();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildFeaturedServices(): array
    {
        return ServiceCardResource::collection($this->serviceCatalog->featuredVisible(self::FEATURED_SERVICES_LIMIT))->resolve();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPopularServices(): array
    {
        return ServiceCardResource::collection($this->serviceCatalog->popularVisible(self::POPULAR_SERVICES_LIMIT))->resolve();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildStoreProducts(): array
    {
        return ProductResource::collection($this->productCatalog->featuredOrLatestVisible(self::STORE_PRODUCTS_LIMIT))->resolve();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildBeforeAfterTeasers(): array
    {
        return BeforeAfterItemResource::collection($this->beforeAfterItems->activeOrdered(self::BEFORE_AFTER_TEASER_LIMIT))->resolve();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildReviewTeasers(): array
    {
        $paginator = $this->reviews->paginatePublished(self::REVIEWS_TEASER_LIMIT);

        return PublicReviewResource::collection($paginator->items())->resolve();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildFaqTeasers(): array
    {
        return FaqResource::collection($this->faqs->activeOrdered(self::FAQ_TEASER_LIMIT))->resolve();
    }

    /**
     * Explicit whitelist of approved public company fields (API Design
     * §5.4) — never a settings dump; SMTP, API keys, internal and
     * authentication settings are unreachable from here.
     *
     * @return array<string, mixed>
     */
    private function buildContact(): array
    {
        /** @var CompanySettingsData $company */
        $company = $this->settings->categorySettings(SettingCategory::Company)->values;

        return [
            'name' => $company->name,
            'phone' => $company->phone,
            'email' => $company->email,
            'whatsapp' => $company->whatsapp,
            'address' => $company->address,
            'working_hours' => [
                'open' => $company->businessHoursOpen,
                'close' => $company->businessHoursClose,
            ],
            'social' => [
                'facebook' => $company->facebook,
                'instagram' => $company->instagram,
            ],
        ];
    }

    /**
     * @template TValue of array
     *
     * @param  callable(): TValue  $build
     * @return TValue
     */
    private function remember(string $key, callable $build): array
    {
        $this->cacheInvalidator->record($key);

        return Cache::remember($key, self::CACHE_TTL_SECONDS, $build);
    }

    /**
     * Standard meta pagination shape (API Design §3.4).
     *
     * @param  LengthAwarePaginator<int, covariant \Illuminate\Database\Eloquent\Model>  $paginator
     * @return array<string, int>
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
