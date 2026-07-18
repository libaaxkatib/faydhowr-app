<?php

namespace App\Services\Favorite;

use App\Contracts\Favorite\Repositories\FavoriteRepositoryInterface;
use App\Contracts\Favorite\Services\FavoriteServiceInterface;
use App\DataTransferObjects\Favorite\CreateFavoriteData;
use App\Exceptions\Favorite\FavoriteServiceNotFoundException;
use App\Models\CustomerProfile;
use App\Models\Favorite;
use App\Models\Service;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class FavoriteService implements FavoriteServiceInterface
{
    public function __construct(private FavoriteRepositoryInterface $favorites) {}

    public function add(CustomerProfile $profile, int $serviceId): array
    {
        $service = $this->favorites->findAccessibleService($serviceId);

        if ($service === null) {
            throw FavoriteServiceNotFoundException::make();
        }

        $existing = $this->favorites->findFor($profile->id, $service->id);

        if ($existing !== null) {
            return ['favorite' => $existing, 'created' => false];
        }

        $favorite = DB::transaction(function () use ($profile, $service): Favorite {
            $favorite = $this->favorites->create(new CreateFavoriteData(
                customerProfileId: $profile->id,
                serviceId: $service->id,
            ));

            $this->favorites->recalculateFavoritesCount($service->id);

            return $favorite;
        });

        return ['favorite' => $favorite, 'created' => true];
    }

    public function remove(CustomerProfile $profile, int $serviceId): void
    {
        $service = $this->favorites->findAccessibleService($serviceId);

        if ($service === null) {
            throw FavoriteServiceNotFoundException::make();
        }

        DB::transaction(function () use ($profile, $service): void {
            $removed = $this->favorites->deleteFor($profile->id, $service->id);

            if ($removed) {
                $this->favorites->recalculateFavoritesCount($service->id);
            }
        });
    }

    public function listForCustomer(CustomerProfile $profile, int $perPage): LengthAwarePaginator
    {
        return $this->favorites->paginateForOwner($profile->id, $perPage);
    }

    public function removeAllForService(Service $service): void
    {
        DB::transaction(function () use ($service): void {
            $removed = $this->favorites->deleteAllForService($service->id);

            if ($removed > 0) {
                $this->favorites->recalculateFavoritesCount($service->id);
            }
        });
    }
}
