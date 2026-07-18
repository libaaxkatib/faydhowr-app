<?php

namespace App\Actions\Inventory;

use App\Enums\StoreOrderStatus;
use App\Models\StoreOrder;

class ProcessStoreOrderPaidStockAction
{
    public function __construct(private DeductStoreOrderStockAction $deductStoreOrderStock) {}

    public function handle(StoreOrder $storeOrder): void
    {
        $storeOrder = StoreOrder::query()
            ->whereKey($storeOrder)
            ->lockForUpdate()
            ->firstOrFail();

        if ($storeOrder->status !== StoreOrderStatus::PendingPayment) {
            return;
        }

        $this->deductStoreOrderStock->handle($storeOrder);

        $storeOrder->update([
            'status' => StoreOrderStatus::Confirmed,
        ]);

        $storeOrder->statusHistories()->create([
            'status' => StoreOrderStatus::Confirmed,
            'changed_by_type' => 'system',
            'changed_by_id' => null,
            'notes' => null,
        ]);
    }
}
