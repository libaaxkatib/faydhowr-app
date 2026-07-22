<?php

namespace App\Actions\Supplier;

use App\Enums\SupplierStatus;
use App\Models\Supplier;
use App\Support\Search\CatalogSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListSuppliersAction
{
    /**
     * @return LengthAwarePaginator<int, Supplier>
     */
    public function handle(
        ?SupplierStatus $status,
        ?string $search,
        int $perPage,
    ): LengthAwarePaginator {
        return Supplier::query()
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($search !== null && $search !== '', function ($query) use ($search): void {
                $term = '%'.CatalogSearch::escapeLike($search).'%';
                $query->where(function ($nested) use ($term): void {
                    $nested
                        ->where('name', 'like', $term)
                        ->orWhere('contact_person', 'like', $term);
                });
            })
            ->latest()
            ->paginate($perPage);
    }
}
