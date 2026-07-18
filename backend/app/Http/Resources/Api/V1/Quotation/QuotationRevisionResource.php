<?php

namespace App\Http\Resources\Api\V1\Quotation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationRevisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version_number' => $this->version_number,
            'source' => $this->source->value,
            'subtotal' => $this->subtotal_amount,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'valid_until' => $this->valid_until->toISOString(),
            'terms' => $this->terms,
            'notes' => $this->notes,
            'issued_by' => $this->whenLoaded(
                'issuedByAdmin',
                fn (): ?string => $this->issuedByAdmin?->full_name,
                fn (): ?string => $this->issued_by_admin_id === null ? null : (string) $this->issued_by_admin_id,
            ),
            'is_latest' => $this->relationLoaded('quotation')
                ? $this->quotation->latest_revision_id === $this->id
                : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
