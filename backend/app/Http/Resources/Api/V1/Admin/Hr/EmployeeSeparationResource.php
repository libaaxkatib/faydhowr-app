<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeSeparationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'separation_date' => $this->separation_date?->toDateString(),
            'rehire_eligible' => $this->rehire_eligible,
            'notes' => $this->notes,
            'separated_by' => $this->whenLoaded('separatedBy', fn () => $this->separatedBy?->full_name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
