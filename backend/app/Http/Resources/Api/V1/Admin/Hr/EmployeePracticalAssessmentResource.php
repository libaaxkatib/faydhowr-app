<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeePracticalAssessmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn () => $this->employee?->full_name),
            'practical_batch_id' => $this->practical_batch_id,
            'attempt_number' => $this->attempt_number,
            'assessed_by' => $this->whenLoaded('assessedBy', fn () => $this->assessedBy?->full_name),
            'assessment_date' => $this->assessment_date?->toDateString(),
            'result' => $this->result->value,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
