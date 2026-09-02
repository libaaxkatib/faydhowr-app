<?php

namespace App\Http\Resources\Api\V1\Admin\Marketing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * amount/status reflect the foundation-only state — amount is null and
 * status is 'pending_calculation' until management approves a formula and
 * a future calculation step runs. Never fabricated here.
 */
class CommissionRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'admin_id' => $this->admin_id,
            'admin_name' => $this->whenLoaded('admin', fn () => $this->admin?->full_name),
            'marketing_record_id' => $this->marketing_record_id,
            'record_number' => $this->whenLoaded('marketingRecord', fn () => $this->marketingRecord?->record_number),
            'type' => $this->type->value,
            'reference_date' => $this->reference_date?->toDateString(),
            'commission_rate_id' => $this->commission_rate_id,
            'amount' => $this->amount,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
