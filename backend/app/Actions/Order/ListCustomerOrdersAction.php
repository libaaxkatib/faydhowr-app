<?php

namespace App\Actions\Order;

use App\Enums\OrderStatus;
use App\Models\CustomerProfile;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListCustomerOrdersAction
{
    /**
     * @return LengthAwarePaginator<int, Order>
     */
    public function handle(
        CustomerProfile $profile,
        ?OrderStatus $status,
        ?int $quotationId,
        int $perPage,
    ): LengthAwarePaginator {
        return $profile
            ->orders()
            ->with('quotation')
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->when($quotationId !== null, fn ($query) => $query->where('quotation_id', $quotationId))
            ->latest()
            ->paginate($perPage);
    }
}
