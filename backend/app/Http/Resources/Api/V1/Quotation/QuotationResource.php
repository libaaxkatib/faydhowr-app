<?php

namespace App\Http\Resources\Api\V1\Quotation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'quotation_number' => $this->quotation_number,
            'booking' => $this->whenLoaded('booking', fn (): ?array => $this->booking === null ? null : [
                'booking_number' => $this->booking->booking_number,
                'status' => $this->booking->status->value,
                'requested_date' => $this->booking->requested_date?->toDateString(),
                'requested_time_window' => $this->booking->requested_time_window,
            ]),
            'status' => $this->status->value,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'valid_until' => $this->valid_until?->toISOString(),
            'accepted_at' => $this->accepted_at?->toISOString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
