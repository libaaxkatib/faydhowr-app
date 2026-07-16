<?php

namespace App\Http\Resources\Api\V1\GoodsReceipt;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'purchase_order_item_id' => $this->purchase_order_item_id,
            'product_id' => $this->product_id,
            'sku' => $this->sku,
            'product_name' => $this->product_name,
            'quantity_received' => $this->quantity_received,
            'unit_cost' => $this->unit_cost,
        ];
    }
}
