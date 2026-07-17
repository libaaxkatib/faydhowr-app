<?php

namespace App\Contracts\Dashboard;

/**
 * Owns dashboard cache invalidation. Business actions call invalidate() after
 * mutating dashboard-related data; the query service records every cache key
 * it writes so invalidation touches dashboard entries only.
 */
interface DashboardCacheInvalidatorInterface
{
    /**
     * Track a dashboard cache key so it can be invalidated later.
     */
    public function record(string $cacheKey): void;

    /**
     * Forget every recorded dashboard cache entry. Unrelated caches are
     * never touched.
     */
    public function invalidate(): void;
}
