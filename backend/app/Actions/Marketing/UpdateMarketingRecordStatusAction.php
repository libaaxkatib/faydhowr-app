<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Enums\Marketing\MarketingRecordStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\MarketingRecord;

class UpdateMarketingRecordStatusAction
{
    public function handle(MarketingRecord $record, MarketingRecordStatus $status, Admin $actor): MarketingRecord
    {
        $from = $record->status;

        $record->update(['status' => $status]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Marketing record '{$record->record_number}' status changed from {$from->value} to {$status->value}.",
            entityType: MarketingRecord::class,
            entityId: $record->id,
            metadata: ['from_status' => $from->value, 'to_status' => $status->value],
        ));

        return $record->load(['assignedTeam', 'assignedAdmin', 'broughtByAdmin', 'xarunDetail', 'projectDetail']);
    }
}
