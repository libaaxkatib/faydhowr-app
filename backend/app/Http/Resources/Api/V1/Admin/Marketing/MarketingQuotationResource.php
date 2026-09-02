<?php

namespace App\Http\Resources\Api\V1\Admin\Marketing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketingQuotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'sent_at' => $this->sent_at?->toDateString(),
            'status' => $this->status->value,
            'notes' => $this->notes,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->full_name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
