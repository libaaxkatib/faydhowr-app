<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeePipelineStage;
use App\Enums\TrainingBatchStatus;
use App\Enums\TrainingParticipantResult;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\TrainingBatch;
use Illuminate\Support\Facades\DB;

/**
 * docs/HRM_MARKETING_SRS.md HR §9: "After successful Training -> NEED
 * PRACTICAL". Participants listed in $absentEmployeeIds are marked absent
 * and do NOT advance; everyone else in the batch is marked completed and
 * moves to pipeline_stage=need_practical.
 */
class CompleteTrainingBatchAction
{
    public function handle(TrainingBatch $batch, array $absentEmployeeIds, Admin $actor): TrainingBatch
    {
        return DB::transaction(function () use ($batch, $absentEmployeeIds, $actor) {
            foreach ($batch->participants as $participant) {
                if (in_array($participant->employee_id, $absentEmployeeIds, true)) {
                    $participant->update(['result' => TrainingParticipantResult::Absent]);

                    continue;
                }

                $participant->update(['result' => TrainingParticipantResult::Completed]);

                Employee::query()
                    ->where('id', $participant->employee_id)
                    ->where('pipeline_stage', EmployeePipelineStage::NeedTraining->value)
                    ->update(['pipeline_stage' => EmployeePipelineStage::NeedPractical->value]);
            }

            $batch->update(['status' => TrainingBatchStatus::Completed]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Training batch '{$batch->batch_number}' marked completed.",
                entityType: TrainingBatch::class,
                entityId: $batch->id,
                metadata: ['absent_employee_ids' => $absentEmployeeIds],
            ));

            return $batch->load('participants.employee');
        });
    }
}
