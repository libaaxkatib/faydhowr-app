<?php

namespace App\Actions\GoodsReceipt;

use App\Models\GoodsReceipt;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListGoodsReceiptsAction
{
    /**
     * @return LengthAwarePaginator<int, GoodsReceipt>
     */
    public function handle(
        ?int $supplierId,
        ?int $purchaseOrderId,
        int $perPage,
    ): LengthAwarePaginator {
        return GoodsReceipt::query()
            ->with(['supplier', 'purchaseOrder', 'items'])
            ->when($supplierId !== null, fn ($query) => $query->where('supplier_id', $supplierId))
            ->when($purchaseOrderId !== null, fn ($query) => $query->where('purchase_order_id', $purchaseOrderId))
            ->latest()
            ->paginate($perPage);
    }
}
