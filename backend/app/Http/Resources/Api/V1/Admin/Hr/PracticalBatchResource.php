<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PracticalBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_number' => $this->batch_number,
            'batch_date' => $this->batch_date?->toDateString(),
            'team_or_group' => $this->team_or_group,
            'trainer_admin_id' => $this->trainer_admin_id,
            'trainer_name' => $this->whenLoaded('trainer', fn () => $this->trainer?->full_name),
            'location' => $this->location,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->full_name),
            'assessments' => EmployeePracticalAssessmentResource::collection($this->whenLoaded('assessments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
