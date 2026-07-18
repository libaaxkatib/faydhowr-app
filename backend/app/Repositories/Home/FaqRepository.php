<?php

namespace App\Repositories\Home;

use App\Contracts\Home\Repositories\FaqRepositoryInterface;
use App\DataTransferObjects\Home\CreateFaqData;
use App\Models\Faq;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class FaqRepository implements FaqRepositoryInterface
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
        return Faq::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function find(int $id): ?Faq
    {
        return Faq::query()->find($id);
    }

    public function create(CreateFaqData $data): Faq
    {
        return Faq::query()->create([
            'question' => $data->question,
            'answer' => $data->answer,
            'sort_order' => $data->sortOrder,
            'is_active' => $data->isActive,
        ]);
    }

    public function update(Faq $faq, array $attributes): Faq
    {
        $faq->fill($attributes)->save();

        return $faq->refresh();
    }

    public function delete(Faq $faq): void
    {
        $faq->delete();
    }

    /**
     * @return Builder<Faq>
     */
    private function activeQuery(): Builder
    {
        return Faq::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
