<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\TrainingBatch;
use App\Models\TrainingBatchParticipant;

class RemoveTrainingBatchParticipantAction
{
    public function handle(TrainingBatch $batch, TrainingBatchParticipant $participant, Admin $actor): TrainingBatch
    {
        $employeeId = $participant->employee_id;
        $participant->delete();

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Employee removed from training batch '{$batch->batch_number}'.",
            entityType: TrainingBatch::class,
            entityId: $batch->id,
            metadata: ['employee_id' => $employeeId],
        ));

        return $batch->load('participants.employee');
    }
}
