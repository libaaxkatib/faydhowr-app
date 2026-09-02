<?php

namespace App\Http\Resources\Api\V1\Admin\Marketing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommissionRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'admin_id' => $this->admin_id,
            'admin_name' => $this->whenLoaded('admin', fn () => $this->admin?->full_name ?? 'Default (all employees)'),
            'rate_type' => $this->rate_type->value,
            'rate_value' => $this->rate_value,
            'effective_from' => $this->effective_from?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
