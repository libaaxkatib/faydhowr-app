<?php

namespace App\Actions\PurchaseOrder;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelPurchaseOrderAction
{
    public function handle(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder): PurchaseOrder {
            $purchaseOrder = PurchaseOrder::query()
                ->whereKey($purchaseOrder)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($purchaseOrder->status, [
                PurchaseOrderStatus::Draft,
                PurchaseOrderStatus::Submitted,
            ], true)) {
                throw new DomainException('Only draft or submitted purchase orders can be cancelled.');
            }

            $purchaseOrder->update([
                'status' => PurchaseOrderStatus::Cancelled,
                'cancelled_at' => now(),
            ]);

            return $purchaseOrder->load(['supplier', 'items']);
        });
    }
}
