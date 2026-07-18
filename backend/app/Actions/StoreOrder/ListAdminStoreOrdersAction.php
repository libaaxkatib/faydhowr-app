<?php

namespace App\Actions\StoreOrder;

use App\DataTransferObjects\StoreOrder\AdminStoreOrderFiltersData;
use App\Models\StoreOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListAdminStoreOrdersAction
{
    /**
     * @return LengthAwarePaginator<int, StoreOrder>
     */
    public function handle(AdminStoreOrderFiltersData $filters): LengthAwarePaginator
    {
        return StoreOrder::query()
            ->with(['customerProfile', 'items'])
            ->when($filters->status, fn ($query) => $query->where('status', $filters->status->value))
            ->when($filters->paymentStatus, fn ($query) => $query->whereHas(
                'payments',
                fn ($paymentQuery) => $paymentQuery->where('status', $filters->paymentStatus->value),
            ))
            ->when($filters->customerProfileId, fn ($query) => $query->where('customer_profile_id', $filters->customerProfileId))
            ->when($filters->from, fn ($query) => $query->whereDate('created_at', '>=', $filters->from))
            ->when($filters->to, fn ($query) => $query->whereDate('created_at', '<=', $filters->to))
            ->when($filters->search, fn ($query) => $query->where('store_order_number', 'like', '%'.$filters->search.'%'))
            ->latest('id')
            ->paginate($filters->perPage);
    }
}
