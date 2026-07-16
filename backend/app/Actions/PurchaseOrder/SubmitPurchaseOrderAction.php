<?php

namespace App\Actions\PurchaseOrder;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use DomainException;
use Illuminate\Support\Facades\DB;

class SubmitPurchaseOrderAction
{
    public function handle(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder): PurchaseOrder {
            $purchaseOrder = PurchaseOrder::query()
                ->whereKey($purchaseOrder)
                ->lockForUpdate()
                ->firstOrFail();

            if ($purchaseOrder->status !== PurchaseOrderStatus::Draft) {
                throw new DomainException('Only draft purchase orders can be submitted.');
            }

            if ($purchaseOrder->items()->count() < 1) {
                throw new DomainException('A purchase order must contain at least one item before submission.');
            }

            $purchaseOrder->update([
                'status' => PurchaseOrderStatus::Submitted,
                'submitted_at' => now(),
            ]);

            return $purchaseOrder->load(['supplier', 'items']);
        });
    }
}
