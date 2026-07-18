<?php

namespace App\Observers\Favorite;

use App\Contracts\Favorite\Services\FavoriteServiceInterface;
use App\Models\Service;

/**
 * Automatically removes favorites when a service becomes inactive or is
 * deleted (SRS FR-094.4). Hard deletes are covered by the cascading FK.
 */
class ServiceFavoriteObserver
{
    public function __construct(private FavoriteServiceInterface $favorites) {}

    public function updated(Service $service): void
    {
        if ($service->wasChanged('is_active') && ! $service->is_active) {
            $this->favorites->removeAllForService($service);
        }
    }

    public function deleted(Service $service): void
    {
        $this->favorites->removeAllForService($service);
    }
}
