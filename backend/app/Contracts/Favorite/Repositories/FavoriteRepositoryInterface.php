<?php

namespace App\Contracts\Favorite\Repositories;

use App\DataTransferObjects\Favorite\CreateFavoriteData;
use App\Models\Favorite;
use App\Models\Service;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface FavoriteRepositoryInterface
{
    /**
     * Resolve a service the customer may favorite: active and not deleted.
     */
    public function findAccessibleService(int $serviceId): ?Service;

    public function findFor(int $customerProfileId, int $serviceId): ?Favorite;

    public function create(CreateFavoriteData $data): Favorite;

    /**
     * @return bool Whether a favorite row was actually removed
     */
    public function deleteFor(int $customerProfileId, int $serviceId): bool;

    public function deleteAllForService(int $serviceId): int;

    /**
     * Favorites with their service card relations, newest-favorited first.
     *
     * @return LengthAwarePaginator<int, Favorite>
     */
    public function paginateForOwner(int $customerProfileId, int $perPage): LengthAwarePaginator;

    /**
     * Recompute the service's cached favorites_count.
     */
    public function recalculateFavoritesCount(int $serviceId): void;
}
