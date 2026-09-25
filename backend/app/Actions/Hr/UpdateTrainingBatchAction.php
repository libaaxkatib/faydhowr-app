<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\TrainingBatch;

class UpdateTrainingBatchAction
{
    public function handle(TrainingBatch $batch, array $data, Admin $actor): TrainingBatch
    {
        $batch->update($data);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Training batch '{$batch->batch_number}' updated.",
            entityType: TrainingBatch::class,
            entityId: $batch->id,
        ));

        return $batch->load('trainer', 'createdBy', 'participants.employee');
    }
}
