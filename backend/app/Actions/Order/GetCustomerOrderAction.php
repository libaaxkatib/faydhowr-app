<?php

namespace App\Actions\Order;

use App\Models\CustomerProfile;
use App\Models\Order;

class GetCustomerOrderAction
{
    public function handle(CustomerProfile $profile, int $orderId): ?Order
    {
        return $profile
            ->orders()
            ->with('quotation')
            ->whereKey($orderId)
            ->first();
    }
}
