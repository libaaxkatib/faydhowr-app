<?php

namespace App\Contracts\Home;

/**
 * Owns Home cache invalidation (API Design §18.1). Content mutations —
 * hero banners, featured services/products, FAQ, gallery, review
 * moderation, and service/product status changes — call invalidate();
 * the Home service records every cache key it writes so invalidation
 * touches Home entries only.
 */
interface HomeCacheInvalidatorInterface
{
    /**
     * Track a Home cache key so it can be invalidated later.
     */
    public function record(string $cacheKey): void;

    /**
     * Forget every recorded Home cache entry. Unrelated caches are
     * never touched.
     */
    public function invalidate(): void;
}
