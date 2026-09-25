<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\PracticalBatchStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\PracticalBatch;
use App\Support\Hr\PracticalBatchCodeGenerator;

class CreatePracticalBatchAction
{
    public function __construct(private PracticalBatchCodeGenerator $codeGenerator) {}

    public function handle(array $data, Admin $actor): PracticalBatch
    {
        $batch = PracticalBatch::query()->create([
            ...$data,
            'batch_number' => $this->codeGenerator->next(),
            'status' => PracticalBatchStatus::Scheduled,
            'created_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Practical batch '{$batch->batch_number}' created.",
            entityType: PracticalBatch::class,
            entityId: $batch->id,
        ));

        return $batch->load('trainer', 'createdBy');
    }
}
