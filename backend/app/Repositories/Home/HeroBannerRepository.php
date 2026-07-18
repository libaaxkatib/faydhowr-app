<?php

namespace App\Repositories\Home;

use App\Contracts\Home\Repositories\HeroBannerRepositoryInterface;
use App\DataTransferObjects\Home\CreateHeroBannerData;
use App\Models\HeroBanner;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class HeroBannerRepository implements HeroBannerRepositoryInterface
{
    public function activeWithinSchedule(): Collection
    {
        $now = now();

        return HeroBanner::query()
            ->where('is_active', true)
            ->where(function ($schedule) use ($now): void {
                $schedule->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($schedule) use ($now): void {
                $schedule->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function paginateForAdmin(int $perPage): LengthAwarePaginator
    {
        return HeroBanner::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function find(int $id): ?HeroBanner
    {
        return HeroBanner::query()->find($id);
    }

    public function create(CreateHeroBannerData $data): HeroBanner
    {
        return HeroBanner::query()->create([
            'title' => $data->title,
            'subtitle' => $data->subtitle,
            'image_url' => $data->imageUrl,
            'action_type' => $data->actionType,
            'action_reference' => $data->actionReference,
            'sort_order' => $data->sortOrder,
            'is_active' => $data->isActive,
            'starts_at' => $data->startsAt,
            'ends_at' => $data->endsAt,
        ]);
    }

    public function update(HeroBanner $banner, array $attributes): HeroBanner
    {
        $banner->fill($attributes)->save();

        return $banner->refresh();
    }

    public function delete(HeroBanner $banner): void
    {
        $banner->delete();
    }
}
