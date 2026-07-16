<?php

namespace App\Actions\PurchaseOrder;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListPurchaseOrdersAction
{
    /**
     * @return LengthAwarePaginator<int, PurchaseOrder>
     */
    public function handle(
        ?PurchaseOrderStatus $status,
        ?int $supplierId,
        int $perPage,
    ): LengthAwarePaginator {
        return PurchaseOrder::query()
            ->with(['supplier', 'items'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($supplierId !== null, fn ($query) => $query->where('supplier_id', $supplierId))
            ->latest()
            ->paginate($perPage);
    }
}
