<?php

namespace App\Actions\Supplier;

use App\Enums\SupplierStatus;
use App\Models\Supplier;
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
                $query->where(function ($nested) use ($search): void {
                    $nested
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('contact_person', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($perPage);
    }
}
