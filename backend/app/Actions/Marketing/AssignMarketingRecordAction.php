<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\MarketingRecord;

class AssignMarketingRecordAction
{
    public function handle(MarketingRecord $record, array $data, Admin $actor): MarketingRecord
    {
        $record->update(array_intersect_key($data, array_flip(['assigned_team_id', 'assigned_admin_id'])));

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Marketing record '{$record->record_number}' assignment updated.",
            entityType: MarketingRecord::class,
            entityId: $record->id,
            metadata: $data,
        ));

        return $record->load(['assignedTeam', 'assignedAdmin', 'broughtByAdmin', 'xarunDetail', 'projectDetail']);
    }
}
