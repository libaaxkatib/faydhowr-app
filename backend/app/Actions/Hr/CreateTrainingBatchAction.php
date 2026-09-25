<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\TrainingBatchStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\TrainingBatch;
use App\Support\Hr\TrainingBatchCodeGenerator;

class CreateTrainingBatchAction
{
    public function __construct(private TrainingBatchCodeGenerator $codeGenerator) {}

    public function handle(array $data, Admin $actor): TrainingBatch
    {
        $batch = TrainingBatch::query()->create([
            ...$data,
            'batch_number' => $this->codeGenerator->next(),
            'status' => TrainingBatchStatus::Scheduled,
            'created_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Training batch '{$batch->batch_number}' created.",
            entityType: TrainingBatch::class,
            entityId: $batch->id,
        ));

        return $batch->load('trainer', 'createdBy');
    }
}
