<?php

namespace App\Http\Resources\Api\V1\StoreOrder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreOrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'sku' => $this->sku_snapshot,
            'product_name' => $this->product_name_snapshot,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price_snapshot,
            'line_total' => $this->line_total_snapshot,
        ];
    }
}
