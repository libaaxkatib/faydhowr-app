<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeUniformResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'status' => $this->status->value,
            'purchased_at' => $this->purchased_at?->toDateString(),
            'received_at' => $this->received_at?->toDateString(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'confirmed_by' => $this->whenLoaded('confirmedBy', fn () => $this->confirmedBy?->full_name),
            'notes' => $this->notes,
        ];
    }
}
