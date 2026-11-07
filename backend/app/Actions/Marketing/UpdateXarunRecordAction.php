<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\MarketingRecord;
use App\Support\Marketing\FeedbackLog;

class UpdateXarunRecordAction
{
    public function handle(MarketingRecord $record, array $data, Admin $actor): MarketingRecord
    {
        $recordFields = array_intersect_key($data, array_flip(['description', 'feedback']));
        $feedbackAdded = null;

        // Owner decision E-7: feedback is appended, never overwritten or removed; an edit that would change the saved text is refused before anything is written.
        if (array_key_exists('feedback', $recordFields)) {
            $accepted = FeedbackLog::acceptEdit($record->feedback, $recordFields['feedback']);
            unset($recordFields['feedback']);

            if ($accepted !== null) {
                $feedbackAdded = FeedbackLog::addedPart($record->feedback, $accepted);
                $recordFields['feedback'] = $accepted;
            }
        }

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
            metadata: $feedbackAdded === null ? null : ['feedback_appended' => $feedbackAdded],
        ));

        return $record->load(['assignedTeam', 'assignedAdmin', 'broughtByAdmin', 'xarunDetail']);
    }
}
