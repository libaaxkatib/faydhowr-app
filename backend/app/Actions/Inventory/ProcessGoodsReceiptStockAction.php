<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\StockLedger;
use DomainException;

class ProcessGoodsReceiptStockAction
{
    public function handle(GoodsReceipt $goodsReceipt): void
    {
        $goodsReceipt->loadMissing('items');

        if ($goodsReceipt->items->isEmpty()) {
            throw new DomainException('Goods receipt has no items to stock.');
        }

        foreach ($goodsReceipt->items as $item) {
            $product = Product::query()
                ->whereKey($item->product_id)
                ->lockForUpdate()
                ->first();

            if ($product === null) {
                throw new DomainException('One or more products could not be found for stock update.');
            }

            $product->current_stock = $product->current_stock + $item->quantity_received;
            $product->save();

            StockLedger::query()->create([
                'product_id' => $product->id,
                'movement_type' => StockMovementType::PurchaseReceipt,
                'quantity' => $item->quantity_received,
                'reference_type' => GoodsReceipt::class,
                'reference_id' => $goodsReceipt->id,
            ]);
        }
    }
}
