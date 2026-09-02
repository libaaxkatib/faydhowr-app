<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\MarketingRecord;

class UpdateProjectRecordAction
{
    public function handle(MarketingRecord $record, array $data, Admin $actor): MarketingRecord
    {
        $recordFields = array_intersect_key($data, array_flip(['description', 'feedback']));

        if ($recordFields !== []) {
            $record->update($recordFields);
        }

        $detailFields = array_intersect_key($data, array_flip([
            'responsible_party_type', 'company_name', 'responsible_person_name', 'phone',
            'location', 'project_type', 'project_size', 'construction_completion_date', 'fayadhowr_work_date',
        ]));

        if ($detailFields !== [] && $record->projectDetail) {
            $record->projectDetail->update($detailFields);
        }

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "PROJECT record '{$record->record_number}' updated.",
            entityType: MarketingRecord::class,
            entityId: $record->id,
        ));

        return $record->load(['assignedTeam', 'assignedAdmin', 'broughtByAdmin', 'projectDetail']);
    }
}
