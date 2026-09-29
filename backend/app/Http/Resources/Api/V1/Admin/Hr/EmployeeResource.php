<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use App\Enums\EmployeeDocumentVerificationStatus;
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
            'secondary_contact_name' => $this->secondary_contact_name,
            'secondary_contact_phone' => $this->secondary_contact_phone,
            'gender' => $this->gender?->value,
            'profile_picture_document_id' => $this->profile_picture_document_id,
            'employee_category_id' => $this->employee_category_id,
            'employee_category_name' => $this->whenLoaded('category', fn () => $this->category?->name),
            'category_specialization' => $this->category_specialization,
            'department_id' => $this->department_id,
            'department_name' => $this->whenLoaded('department', fn () => $this->department?->name),
            'position_id' => $this->position_id,
            'position_name' => $this->whenLoaded('position', fn () => $this->position?->name),
            'status' => $this->status->value,
            'pipeline_stage' => $this->pipeline_stage?->value,
            'guarantor_confirmed_at' => $this->guarantor_confirmed_at?->toIso8601String(),
            'guarantor_needed' => $this->guarantor_needed,
            // Computed, never stored: guarantor_needed itself is historical migration
            // data and must never be rewritten when this becomes true. Requires
            // 'guarantor' and 'documents.category' to be eager-loaded by the caller.
            'damiin_completed' => $this->guarantor?->verified_at !== null
                && $this->documents->contains(
                    fn ($doc) => $doc->verification_status === EmployeeDocumentVerificationStatus::Verified
                        && $doc->category?->name === 'Guarantor Documents'
                ),
            // Computed, never stored: distinguishes the 598 people migrated from the
            // CANCELED sheet / RED REGISTRATION rows (Issue #7) from a live-workflow
            // pipeline_stage='rejected' outcome — the two are never the same thing.
            // Requires 'separations' to be eager-loaded by the caller.
            'is_historical_rejected' => $this->whenLoaded(
                'separations',
                fn () => $this->separations->contains(
                    fn ($s) => str_contains((string) $s->notes, 'Migrated cancellation from Excel HR workbook')
                ),
                false,
            ),
            'waiting_since' => $this->waiting_since?->toIso8601String(),
            'is_supervisor' => $this->is_supervisor,
            'supervisor_since' => $this->supervisor_since?->toIso8601String(),
            'application_date' => $this->application_date?->toDateString(),
            'joining_date' => $this->joining_date?->toDateString(),
            'experience' => $this->experience,
            'training_fee_amount' => $this->training_fee_amount,
            'training_fee_status' => $this->training_fee_status,
            'source' => $this->source,
            'notes' => $this->notes,
            'profile_complete' => $this->profile_complete,
            'created_at' => $this->created_at?->toIso8601String(),
            'status_histories' => EmployeeStatusHistoryResource::collection($this->whenLoaded('statusHistories')),
            'practical_assessments' => EmployeePracticalAssessmentResource::collection($this->whenLoaded('practicalAssessments')),
            'documents' => EmployeeDocumentResource::collection($this->whenLoaded('documents')),
            'active_work_assignments' => WorkAssignmentResource::collection($this->whenLoaded('activeWorkAssignments')),
            'work_assignments' => WorkAssignmentResource::collection($this->whenLoaded('workAssignments')),
            'guarantor' => $this->whenLoaded('guarantor', fn () => $this->guarantor ? new EmployeeGuarantorResource($this->guarantor) : null),
            'current_contract' => $this->whenLoaded('currentContract', fn () => $this->currentContract ? new EmployeeContractResource($this->currentContract) : null),
            'contracts' => EmployeeContractResource::collection($this->whenLoaded('contracts')),
            'uniform' => $this->whenLoaded('uniform', fn () => $this->uniform ? new EmployeeUniformResource($this->uniform) : null),
            'workforce_request_matches_count' => $this->whenLoaded('workforceRequestMatches', fn () => $this->workforceRequestMatches->count()),
            'separations' => EmployeeSeparationResource::collection($this->whenLoaded('separations')),
            // Historical HR completion (Issue #12) — display/reporting evidence only,
            // never live operational state. Empty for anyone without a Green/Waiting
            // List migration source; never fabricated.
            'historical_completions' => EmployeeHistoricalCompletionResource::collection($this->whenLoaded('historicalCompletions')),
            'latest_separation' => $this->whenLoaded('latestSeparation', fn () => $this->latestSeparation ? new EmployeeSeparationResource($this->latestSeparation) : null),
            'current_salary' => $this->whenLoaded('activeWorkAssignments', function () {
                $assignment = $this->activeWorkAssignments->first();

                return $assignment ? [
                    'amount' => $assignment->salary_amount,
                    'currency' => $assignment->salary_currency,
                    'frequency' => $assignment->salary_frequency->value,
                ] : null;
            }),
            'attendances' => EmployeeAttendanceResource::collection($this->whenLoaded('attendances')),
            'leaves' => EmployeeLeaveResource::collection($this->whenLoaded('leaves')),
            'performance_reviews' => EmployeePerformanceReviewResource::collection($this->whenLoaded('performanceReviews')),
            'payments' => EmployeePaymentResource::collection($this->whenLoaded('payments')),
            'penalties' => EmployeePenaltyResource::collection($this->whenLoaded('penalties')),
            'advances' => EmployeeAdvanceResource::collection($this->whenLoaded('advances')),
            'training_history' => EmployeeTrainingHistoryResource::collection($this->whenLoaded('trainingParticipations')),
        ];
    }
}
