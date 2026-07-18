<?php

namespace App\Actions\Payment;

use App\DataTransferObjects\Payment\AdminPaymentFiltersData;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListAdminPaymentsAction
{
    /**
     * @return LengthAwarePaginator<int, Payment>
     */
    public function handle(AdminPaymentFiltersData $filters): LengthAwarePaginator
    {
        return Payment::query()
            ->with(['customerProfile', 'payable'])
            ->when($filters->status, fn ($query) => $query->where('status', $filters->status->value))
            ->when($filters->paymentMethod, fn ($query) => $query->where('payment_method', $filters->paymentMethod->value))
            ->when($filters->paymentStage, fn ($query) => $query->where('payment_stage', $filters->paymentStage->value))
            ->when($filters->payableType, fn ($query) => $query->where(
                'payable_type',
                $filters->payableType === 'store_order' ? StoreOrder::class : Order::class,
            ))
            ->when($filters->customerProfileId, fn ($query) => $query->where('customer_profile_id', $filters->customerProfileId))
            ->when($filters->from, fn ($query) => $query->whereDate('created_at', '>=', $filters->from))
            ->when($filters->to, fn ($query) => $query->whereDate('created_at', '<=', $filters->to))
            ->when($filters->search, fn ($query) => $query->where(function ($query) use ($filters): void {
                $query->where('payment_number', 'like', '%'.$filters->search.'%')
                    ->orWhere('receipt_number', 'like', '%'.$filters->search.'%');
            }))
            ->latest('id')
            ->paginate($filters->perPage);
    }
}
