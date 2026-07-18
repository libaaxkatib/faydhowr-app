<?php

namespace App\Contracts\Favorite\Services;

use App\Models\CustomerProfile;
use App\Models\Favorite;
use App\Models\Service;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface FavoriteServiceInterface
{
    /**
     * Add a service to the customer's favorites. Idempotent: returns the
     * existing favorite with created=false when already favorited.
     *
     * @return array{favorite: Favorite, created: bool}
     */
    public function add(CustomerProfile $profile, int $serviceId): array;

    /**
     * Remove a service from the customer's favorites. Idempotent: succeeds
     * when the accessible service is not currently favorited.
     */
    public function remove(CustomerProfile $profile, int $serviceId): void;

    /**
     * @return LengthAwarePaginator<int, Favorite>
     */
    public function listForCustomer(CustomerProfile $profile, int $perPage): LengthAwarePaginator;

    /**
     * Automatic removal (SRS FR-094.4): purge all favorites of a service
     * that became inactive or was deleted, and reset its cached count.
     */
    public function removeAllForService(Service $service): void;
}
