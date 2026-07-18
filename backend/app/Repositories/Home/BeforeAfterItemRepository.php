<?php

namespace App\Repositories\Home;

use App\Contracts\Home\Repositories\BeforeAfterItemRepositoryInterface;
use App\DataTransferObjects\Home\CreateBeforeAfterItemData;
use App\Models\BeforeAfterItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class BeforeAfterItemRepository implements BeforeAfterItemRepositoryInterface
{
    public function activeOrdered(int $limit): Collection
    {
        return $this->activeQuery()
            ->limit($limit)
            ->get();
    }

    public function paginateActive(int $perPage): LengthAwarePaginator
    {
        return $this->activeQuery()->paginate($perPage);
    }

    public function paginateForAdmin(int $perPage): LengthAwarePaginator
    {
        return BeforeAfterItem::query()
            ->with('service')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function find(int $id): ?BeforeAfterItem
    {
        return BeforeAfterItem::query()->find($id);
    }

    public function create(CreateBeforeAfterItemData $data): BeforeAfterItem
    {
        return BeforeAfterItem::query()->create([
            'service_id' => $data->serviceId,
            'title' => $data->title,
            'before_image_url' => $data->beforeImageUrl,
            'after_image_url' => $data->afterImageUrl,
            'sort_order' => $data->sortOrder,
            'is_active' => $data->isActive,
        ]);
    }

    public function update(BeforeAfterItem $item, array $attributes): BeforeAfterItem
    {
        $item->fill($attributes)->save();

        return $item->refresh();
    }

    public function delete(BeforeAfterItem $item): void
    {
        $item->delete();
    }

    /**
     * @return Builder<BeforeAfterItem>
     */
    private function activeQuery(): Builder
    {
        return BeforeAfterItem::query()
            ->where('is_active', true)
            ->with('service')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
