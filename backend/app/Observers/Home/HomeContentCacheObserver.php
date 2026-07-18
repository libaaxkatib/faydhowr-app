<?php

namespace App\Observers\Home;

use App\Contracts\Home\HomeCacheInvalidatorInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Flushes Home caches whenever content that feeds the Home APIs mutates
 * (API Design §18.1): hero banners, gallery items, FAQs, services (status
 * and featured curation), products (status and featured curation), and
 * review moderation. Runs after the surrounding transaction commits so
 * guests never cache uncommitted state.
 */
class HomeContentCacheObserver
{
    public bool $afterCommit = true;

    public function __construct(private HomeCacheInvalidatorInterface $cacheInvalidator) {}

    public function saved(Model $model): void
    {
        $this->cacheInvalidator->invalidate();
    }

    public function deleted(Model $model): void
    {
        $this->cacheInvalidator->invalidate();
    }

    public function restored(Model $model): void
    {
        $this->cacheInvalidator->invalidate();
    }
}
