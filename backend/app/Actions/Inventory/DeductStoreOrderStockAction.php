<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\StoreOrder;
use DomainException;

/**
 * Deducts stock for every store order line and writes the customer-sale
 * ledger entries. Must be called inside a database transaction.
 */
class DeductStoreOrderStockAction
{
    public function handle(StoreOrder $storeOrder): void
    {
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
                throw new DomainException('Insufficient stock to fulfill the store order.');
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
    }
}
