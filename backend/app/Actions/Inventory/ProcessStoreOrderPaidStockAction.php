<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Enums\StoreOrderStatus;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\StoreOrder;
use DomainException;

class ProcessStoreOrderPaidStockAction
{
    public function handle(StoreOrder $storeOrder): void
    {
        $storeOrder = StoreOrder::query()
            ->whereKey($storeOrder)
            ->lockForUpdate()
            ->firstOrFail();

        if ($storeOrder->status !== StoreOrderStatus::PendingPayment) {
            return;
        }

        $items = $storeOrder->items()->lockForUpdate()->get();

        if ($items->isEmpty()) {
            throw new DomainException('Store order has no items to deduct from stock.');
        }

        foreach ($items as $item) {
            if ($item->product_id === null) {
                throw new DomainException('Store order item is missing a product reference.');
            }

            $product = Product::query()
                ->whereKey($item->product_id)
                ->lockForUpdate()
                ->first();

            if ($product === null) {
                throw new DomainException('One or more products could not be found for stock update.');
            }

            if ($product->current_stock < $item->quantity) {
                throw new DomainException('Insufficient stock to fulfill the paid store order.');
            }

            $product->current_stock = $product->current_stock - $item->quantity;
            $product->save();

            StockLedger::query()->create([
                'product_id' => $product->id,
                'movement_type' => StockMovementType::CustomerSale,
                'quantity' => -$item->quantity,
                'reference_type' => StoreOrder::class,
                'reference_id' => $storeOrder->id,
            ]);
        }

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
