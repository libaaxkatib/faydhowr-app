<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeePipelineStage;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\TrainingBatch;
use App\Models\TrainingBatchParticipant;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * docs/HRM_MARKETING_SRS.md HR §9: "Only eligible people from NEED TRAINING
 * may be assigned" to a batch — automatic eligibility is not automatic
 * assignment, but HR still can't add someone who was never eligible.
 * Enforced the same way CreateWorkAssignmentAction enforces its rules.
 */
class AddTrainingBatchParticipantAction
{
    public function handle(TrainingBatch $batch, Employee $employee, Admin $actor): TrainingBatch
    {
        if ($employee->pipeline_stage !== EmployeePipelineStage::NeedTraining) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$employee->full_name}' is not currently eligible for training (must be in the Need Training queue).",
                    'EMPLOYEE_NOT_ELIGIBLE_FOR_TRAINING',
                    422,
                ),
            );
        }

        TrainingBatchParticipant::query()->create([
            'training_batch_id' => $batch->id,
            'employee_id' => $employee->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Employee '{$employee->full_name}' added to training batch '{$batch->batch_number}'.",
            entityType: TrainingBatch::class,
            entityId: $batch->id,
            metadata: ['employee_id' => $employee->id],
        ));

        return $batch->load('participants.employee');
    }
}
