<?php

namespace App\Contracts\Home\Services;

interface HomeServiceInterface
{
    /**
     * Full Home aggregate (API Design §5.1): sections in UX order plus
     * generation metadata, served from the 5-minute cache.
     *
     * @return array{sections: array<string, mixed>, meta: array{generated_at: string, cache_expires_at: string, version: string}}
     */
    public function aggregate(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function heroBanners(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function serviceCategories(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function featuredServices(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function storeProducts(): array;

    /**
     * @return array{items: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function beforeAfter(int $page, int $perPage): array;

    /**
     * @return array{items: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function reviews(int $page, int $perPage): array;

    /**
     * @return array{items: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function faqs(int $page, int $perPage): array;

    /**
     * Approved public company fields only (API Design §5.4).
     *
     * @return array<string, mixed>
     */
    public function contact(): array;
}
