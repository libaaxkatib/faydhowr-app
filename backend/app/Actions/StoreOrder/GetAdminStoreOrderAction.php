<?php

namespace App\Actions\StoreOrder;

use App\Models\StoreOrder;

class GetAdminStoreOrderAction
{
    public function handle(int $storeOrderId): ?StoreOrder
    {
        return StoreOrder::query()
            ->with(['customerProfile', 'items', 'statusHistories', 'payments'])
            ->whereKey($storeOrderId)
            ->first();
    }
}
