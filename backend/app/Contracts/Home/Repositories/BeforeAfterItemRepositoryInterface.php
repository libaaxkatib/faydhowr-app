<?php

namespace App\Contracts\Home\Repositories;

use App\DataTransferObjects\Home\CreateBeforeAfterItemData;
use App\Models\BeforeAfterItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface BeforeAfterItemRepositoryInterface
{
    /**
     * Publicly visible gallery items ordered by sort_order.
     *
     * @return Collection<int, BeforeAfterItem>
     */
    public function activeOrdered(int $limit): Collection;

    /**
     * Public paginated gallery (active items only).
     *
     * @return LengthAwarePaginator<int, BeforeAfterItem>
     */
    public function paginateActive(int $perPage): LengthAwarePaginator;

    /**
     * Admin listing: includes inactive items.
     *
     * @return LengthAwarePaginator<int, BeforeAfterItem>
     */
    public function paginateForAdmin(int $perPage): LengthAwarePaginator;

    public function find(int $id): ?BeforeAfterItem;

    public function create(CreateBeforeAfterItemData $data): BeforeAfterItem;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(BeforeAfterItem $item, array $attributes): BeforeAfterItem;

    public function delete(BeforeAfterItem $item): void;
}
