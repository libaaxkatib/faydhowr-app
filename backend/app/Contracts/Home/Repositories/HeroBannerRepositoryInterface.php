<?php

namespace App\Contracts\Home\Repositories;

use App\DataTransferObjects\Home\CreateHeroBannerData;
use App\Models\HeroBanner;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface HeroBannerRepositoryInterface
{
    /**
     * Publicly visible banners: active and inside their schedule window
     * (open bounds allowed), ordered by sort_order (API Design §5.3).
     *
     * @return Collection<int, HeroBanner>
     */
    public function activeWithinSchedule(): Collection;

    /**
     * Admin listing: includes inactive and out-of-schedule banners.
     *
     * @return LengthAwarePaginator<int, HeroBanner>
     */
    public function paginateForAdmin(int $perPage): LengthAwarePaginator;

    public function find(int $id): ?HeroBanner;

    public function create(CreateHeroBannerData $data): HeroBanner;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(HeroBanner $banner, array $attributes): HeroBanner;

    public function delete(HeroBanner $banner): void;
}
