<?php

namespace App\Services\Dashboard;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Dashboard cache keys embed the active date filter and custom date range, so
 * the full key set cannot be enumerated upfront. The query service records
 * each key it writes into a cache-stored index; invalidation forgets exactly
 * those keys (and the index itself), leaving unrelated caches intact. This
 * works on every cache driver, unlike cache tags.
 */
class DashboardCacheInvalidator implements DashboardCacheInvalidatorInterface
{
    public const KEY_INDEX = 'dashboard:widgets:key-index';

    public function record(string $cacheKey): void
    {
        /** @var list<string> $keys */
        $keys = Cache::get(self::KEY_INDEX, []);

        if (! in_array($cacheKey, $keys, true)) {
            $keys[] = $cacheKey;
            Cache::forever(self::KEY_INDEX, $keys);
        }
    }

    public function invalidate(): void
    {
        /** @var list<string> $keys */
        $keys = Cache::pull(self::KEY_INDEX, []);

        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }
}
