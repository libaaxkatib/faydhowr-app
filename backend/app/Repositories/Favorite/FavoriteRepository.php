<?php

namespace App\Repositories\Favorite;

use App\Contracts\Favorite\Repositories\FavoriteRepositoryInterface;
use App\DataTransferObjects\Favorite\CreateFavoriteData;
use App\Models\Favorite;
use App\Models\Service;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class FavoriteRepository implements FavoriteRepositoryInterface
{
    public function findAccessibleService(int $serviceId): ?Service
    {
        return Service::query()
            ->whereKey($serviceId)
            ->where('is_active', true)
            ->first();
    }

    public function findFor(int $customerProfileId, int $serviceId): ?Favorite
    {
        return Favorite::query()
            ->where('customer_profile_id', $customerProfileId)
            ->where('service_id', $serviceId)
            ->first();
    }

    public function create(CreateFavoriteData $data): Favorite
    {
        return Favorite::query()->create([
            'customer_profile_id' => $data->customerProfileId,
            'service_id' => $data->serviceId,
        ]);
    }

    public function deleteFor(int $customerProfileId, int $serviceId): bool
    {
        return Favorite::query()
            ->where('customer_profile_id', $customerProfileId)
            ->where('service_id', $serviceId)
            ->delete() > 0;
    }

    public function deleteAllForService(int $serviceId): int
    {
        return Favorite::query()
            ->where('service_id', $serviceId)
            ->delete();
    }

    public function paginateForOwner(int $customerProfileId, int $perPage): LengthAwarePaginator
    {
        return Favorite::query()
            ->where('customer_profile_id', $customerProfileId)
            ->whereHas('service', fn ($service) => $service->where('is_active', true))
            ->with([
                'service.modes' => fn ($modes) => $modes->where('is_active', true)->orderBy('id'),
                'service.coverageCities' => fn ($cities) => $cities->where('is_active', true)->orderBy('city'),
                'service.media' => fn ($media) => $media->orderBy('sort_order')->orderBy('id'),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function recalculateFavoritesCount(int $serviceId): void
    {
        Service::query()->withTrashed()->whereKey($serviceId)->update([
            'favorites_count' => Favorite::query()
                ->where('service_id', $serviceId)
                ->count(),
        ]);
    }
}
