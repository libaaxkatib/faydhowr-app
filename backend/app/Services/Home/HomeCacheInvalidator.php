<?php

namespace App\Services\Home;

use App\Contracts\Home\HomeCacheInvalidatorInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Home cache keys include pagination parameters for paginated sections, so
 * the full key set cannot be enumerated upfront. The Home service records
 * each key it writes into a cache-stored index; invalidation forgets exactly
 * those keys (and the index itself), leaving unrelated caches intact. This
 * works on every cache driver, unlike cache tags.
 */
class HomeCacheInvalidator implements HomeCacheInvalidatorInterface
{
    public const KEY_INDEX = 'home:key-index';

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
