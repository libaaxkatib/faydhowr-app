<?php

namespace App\Actions\StoreOrder;

use App\Models\CustomerProfile;
use App\Models\StoreOrder;

class GetCustomerStoreOrderAction
{
    public function handle(CustomerProfile $profile, int $storeOrderId): ?StoreOrder
    {
        return $profile
            ->storeOrders()
            ->with(['items', 'statusHistories'])
            ->whereKey($storeOrderId)
            ->first();
    }
}
