<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\MarketingRecord;

class UpdateXarunRecordAction
{
    public function handle(MarketingRecord $record, array $data, Admin $actor): MarketingRecord
    {
        $recordFields = array_intersect_key($data, array_flip(['description', 'feedback']));

        if ($recordFields !== []) {
            $record->update($recordFields);
        }

        $detailFields = array_intersect_key($data, array_flip([
            'facility_name', 'manager_name', 'manager_title', 'phone', 'location', 'needs',
        ]));

        if ($detailFields !== [] && $record->xarunDetail) {
            $record->xarunDetail->update($detailFields);
        }

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "XARUN record '{$record->record_number}' updated.",
            entityType: MarketingRecord::class,
            entityId: $record->id,
        ));

        return $record->load(['assignedTeam', 'assignedAdmin', 'broughtByAdmin', 'xarunDetail']);
    }
}
