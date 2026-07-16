<?php

namespace App\Actions\GoodsReceipt;

use App\Models\GoodsReceipt;

class GetGoodsReceiptAction
{
    public function handle(int $goodsReceiptId): ?GoodsReceipt
    {
        return GoodsReceipt::query()
            ->with(['supplier', 'purchaseOrder', 'items'])
            ->whereKey($goodsReceiptId)
            ->first();
    }
}
