<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'alternate_phone' => $this->alternate_phone,
            'location' => $this->location,
            'age' => $this->age,
            'marital_status' => $this->marital_status,
            'lives_with' => $this->lives_with,
            'reference_name' => $this->reference_name,
            'employee_category_id' => $this->employee_category_id,
            'employee_category_name' => $this->whenLoaded('category', fn () => $this->category?->name),
            'department_id' => $this->department_id,
            'department_name' => $this->whenLoaded('department', fn () => $this->department?->name),
            'position_id' => $this->position_id,
            'position_name' => $this->whenLoaded('position', fn () => $this->position?->name),
            'status' => $this->status->value,
            'guarantor_confirmed_at' => $this->guarantor_confirmed_at?->toIso8601String(),
            'application_date' => $this->application_date?->toDateString(),
            'joining_date' => $this->joining_date?->toDateString(),
            'experience' => $this->experience,
            'training_fee_amount' => $this->training_fee_amount,
            'training_fee_status' => $this->training_fee_status,
            'source' => $this->source,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'status_histories' => EmployeeStatusHistoryResource::collection($this->whenLoaded('statusHistories')),
            'practical_assessments' => EmployeePracticalAssessmentResource::collection($this->whenLoaded('practicalAssessments')),
            'documents' => EmployeeDocumentResource::collection($this->whenLoaded('documents')),
            'active_work_assignments' => WorkAssignmentResource::collection($this->whenLoaded('activeWorkAssignments')),
            'work_assignments' => WorkAssignmentResource::collection($this->whenLoaded('workAssignments')),
        ];
    }
}
