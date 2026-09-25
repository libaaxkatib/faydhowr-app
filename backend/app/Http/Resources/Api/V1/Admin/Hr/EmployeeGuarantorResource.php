<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeGuarantorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'guarantor_name' => $this->guarantor_name,
            'guarantor_phone' => $this->guarantor_phone,
            'relationship' => $this->relationship,
            'other_info' => $this->other_info,
            'collected_date' => $this->collected_date?->toDateString(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verified_by' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy?->full_name),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->full_name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
