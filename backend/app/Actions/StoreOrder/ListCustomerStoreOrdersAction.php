<?php

namespace App\Actions\StoreOrder;

use App\Enums\StoreOrderStatus;
use App\Models\CustomerProfile;
use App\Models\StoreOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListCustomerStoreOrdersAction
{
    /**
     * @return LengthAwarePaginator<int, StoreOrder>
     */
    public function handle(
        CustomerProfile $profile,
        ?StoreOrderStatus $status,
        int $perPage,
    ): LengthAwarePaginator {
        return $profile
            ->storeOrders()
            ->with('items')
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->latest()
            ->paginate($perPage);
    }
}
