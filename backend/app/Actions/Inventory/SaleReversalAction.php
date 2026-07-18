<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\StoreOrder;
use DomainException;

/**
 * Restores stock for every store order line after a COD payment rejection
 * cancels the order (Sprint 27) and writes the `sale_reversal` ledger
 * entries. Never exposed through an API endpoint; must be called inside the
 * same database transaction as the cancellation it reverses.
 */
class SaleReversalAction
{
    public function handle(StoreOrder $storeOrder): void
    {
        $items = $storeOrder->items()->lockForUpdate()->get();

        if ($items->isEmpty()) {
            throw new DomainException('Store order has no items to restore to stock.');
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
                throw new DomainException('One or more products could not be found for stock restore.');
            }

            $product->current_stock = $product->current_stock + $item->quantity;
            $product->save();

            StockLedger::query()->create([
                'product_id' => $product->id,
                'movement_type' => StockMovementType::SaleReversal,
                'quantity' => $item->quantity,
                'reference_type' => StoreOrder::class,
                'reference_id' => $storeOrder->id,
            ]);
        }
    }
}
