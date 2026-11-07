<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\MarketingRecord;
use App\Support\Marketing\FeedbackLog;
use App\Support\Marketing\WorkDateFollowUp;

class UpdateProjectRecordAction
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

        $oldWorkDate = $record->projectDetail?->fayadhowr_work_date?->toDateString();

        $detailFields = array_intersect_key($data, array_flip([
            'responsible_party_type', 'company_name', 'responsible_person_name', 'phone',
            'location', 'project_type', 'project_size', 'construction_completion_date', 'fayadhowr_work_date',
        ]));

        if ($detailFields !== [] && $record->projectDetail) {
            $record->projectDetail->update($detailFields);
        }

        // Owner decision E-10: a Work Date that was recorded or changed gets its follow-up automatically (once per record and date; the old date's follow-up stays).
        $newWorkDate = $record->projectDetail?->fresh()?->fayadhowr_work_date?->toDateString();
        $autoFollowUp = $newWorkDate !== null && $newWorkDate !== $oldWorkDate ? WorkDateFollowUp::ensure($record, $newWorkDate, $actor) : null;

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "PROJECT record '{$record->record_number}' updated.",
            entityType: MarketingRecord::class,
            entityId: $record->id,
            metadata: array_filter(['feedback_appended' => $feedbackAdded, 'work_date_follow_up_id' => $autoFollowUp?->id], fn ($value) => $value !== null),
        ));

        return $record->load(['assignedTeam', 'assignedAdmin', 'broughtByAdmin', 'projectDetail']);
    }
}
