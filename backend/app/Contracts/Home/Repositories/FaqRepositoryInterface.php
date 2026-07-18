<?php

namespace App\Contracts\Home\Repositories;

use App\DataTransferObjects\Home\CreateFaqData;
use App\Models\Faq;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface FaqRepositoryInterface
{
    /**
     * Publicly visible FAQs ordered by sort_order.
     *
     * @return Collection<int, Faq>
     */
    public function activeOrdered(int $limit): Collection;

    /**
     * Public paginated FAQ listing (active entries only).
     *
     * @return LengthAwarePaginator<int, Faq>
     */
    public function paginateActive(int $perPage): LengthAwarePaginator;

    /**
     * Admin listing: includes inactive entries.
     *
     * @return LengthAwarePaginator<int, Faq>
     */
    public function paginateForAdmin(int $perPage): LengthAwarePaginator;

    public function find(int $id): ?Faq;

    public function create(CreateFaqData $data): Faq;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Faq $faq, array $attributes): Faq;

    public function delete(Faq $faq): void;
}
