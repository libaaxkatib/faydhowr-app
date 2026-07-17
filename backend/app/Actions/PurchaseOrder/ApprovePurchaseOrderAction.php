<?php

namespace App\Actions\PurchaseOrder;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use DomainException;
use Illuminate\Support\Facades\DB;

class ApprovePurchaseOrderAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    public function handle(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        $purchaseOrder = DB::transaction(function () use ($purchaseOrder): PurchaseOrder {
            $purchaseOrder = PurchaseOrder::query()
                ->whereKey($purchaseOrder)
                ->lockForUpdate()
                ->firstOrFail();

            if ($purchaseOrder->status !== PurchaseOrderStatus::Submitted) {
                throw new DomainException('Only submitted purchase orders can be approved.');
            }

            $purchaseOrder->update([
                'status' => PurchaseOrderStatus::Approved,
                'approved_at' => now(),
            ]);

            $purchaseOrder->statusHistories()->create([
                'status' => PurchaseOrderStatus::Approved,
                'changed_by_type' => 'admin',
                'changed_by_id' => null,
                'notes' => null,
            ]);

            return $purchaseOrder->load(['supplier', 'items', 'statusHistories']);
        });

        $this->dashboardCache->invalidate();

        return $purchaseOrder;
    }
}
