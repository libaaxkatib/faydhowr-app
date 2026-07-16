<?php

namespace App\Actions\PurchaseOrder;

use App\Models\PurchaseOrder;

class GetPurchaseOrderAction
{
    public function handle(int $purchaseOrderId): ?PurchaseOrder
    {
        return PurchaseOrder::query()
            ->with(['supplier', 'items'])
            ->whereKey($purchaseOrderId)
            ->first();
    }
}
