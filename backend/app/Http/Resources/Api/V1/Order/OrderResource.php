<?php

namespace App\Http\Resources\Api\V1\Order;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'order_number' => $this->order_number,
            'quotation' => $this->whenLoaded('quotation', fn (): array => [
                'quotation_number' => $this->quotation->quotation_number,
                'status' => $this->quotation->status->value,
            ]),
            'status' => $this->status->value,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'notes' => $this->notes,
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
