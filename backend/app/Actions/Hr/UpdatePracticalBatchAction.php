<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\PracticalBatch;

class UpdatePracticalBatchAction
{
    public function handle(PracticalBatch $batch, array $data, Admin $actor): PracticalBatch
    {
        $batch->update($data);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Practical batch '{$batch->batch_number}' updated.",
            entityType: PracticalBatch::class,
            entityId: $batch->id,
        ));

        return $batch->load('trainer', 'createdBy', 'assessments.employee');
    }
}
