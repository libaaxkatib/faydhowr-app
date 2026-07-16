<?php

namespace App\Actions\StoreOrder;

use App\Enums\StoreOrderStatus;
use App\Models\CustomerProfile;
use App\Models\StoreOrder;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelStoreOrderAction
{
    public function handle(
        CustomerProfile $profile,
        int $storeOrderId,
        ?string $cancellationReason,
    ): ?StoreOrder {
        return DB::transaction(function () use ($profile, $storeOrderId, $cancellationReason): ?StoreOrder {
            $profile = CustomerProfile::query()
                ->whereKey($profile)
                ->lockForUpdate()
                ->firstOrFail();

            $storeOrder = $profile
                ->storeOrders()
                ->whereKey($storeOrderId)
                ->lockForUpdate()
                ->first();

            if ($storeOrder === null) {
                return null;
            }

            if ($storeOrder->status !== StoreOrderStatus::PendingPayment) {
                throw new DomainException('This store order cannot be cancelled.');
            }

            $storeOrder->status = StoreOrderStatus::Cancelled;
            $storeOrder->cancelled_at = now();
            $storeOrder->cancellation_reason = $cancellationReason;
            $storeOrder->save();

            $storeOrder->statusHistories()->create([
                'status' => StoreOrderStatus::Cancelled,
                'changed_by_type' => 'user',
                'changed_by_id' => $profile->user_id,
                'notes' => $cancellationReason,
            ]);

            return $storeOrder->load(['items', 'statusHistories']);
        });
    }
}
