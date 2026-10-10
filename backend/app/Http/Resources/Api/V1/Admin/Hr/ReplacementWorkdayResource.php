<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReplacementWorkdayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'temporary_replacement_id' => $this->temporary_replacement_id,
            'work_date' => $this->work_date?->toDateString(),
            'status' => $this->status,
            'notes' => $this->notes,
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->full_name),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verified_by' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy?->full_name),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
        ];
    }
}
