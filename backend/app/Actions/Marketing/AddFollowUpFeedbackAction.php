<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Enums\Marketing\FollowUpAction as FollowUpActionEnum;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\FollowUp;
use App\Models\FollowUpHistory;
use App\Models\MarketingRecord;
use App\Support\Marketing\FeedbackLog;
use Illuminate\Support\Facades\DB;

/**
 * SRS §15 "Add feedback" reminder action, triggered from a follow-up. The
 * feedback itself lives on the parent marketing record (the same field
 * StoreXarun/ProjectRequest already validate) — this just gives the
 * follow-up UI a way to write it and logs the action against the follow-up's
 * own history, per §15's "System-ku waa inuu hayaa Follow-up History."
 *
 * Owner decision E-7: the new feedback is APPENDED to what the record already holds (FeedbackLog); the earlier text is never overwritten. The follow-up
 * history row keeps the entry just added.
 */
class AddFollowUpFeedbackAction
{
    public function handle(FollowUp $followUp, string $feedback, Admin $actor): FollowUp
    {
        return DB::transaction(function () use ($followUp, $feedback, $actor) {
            $record = $followUp->marketingRecord;
            $record->update(['feedback' => FeedbackLog::append($record->feedback, $feedback)]);

            FollowUpHistory::query()->create([
                'follow_up_id' => $followUp->id,
                'action' => FollowUpActionEnum::FeedbackUpdated,
                'note' => $feedback,
                'performed_by' => $actor->id,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Feedback added to marketing record '{$record->record_number}' via follow-up #{$followUp->id}.",
                entityType: MarketingRecord::class,
                entityId: $record->id,
                metadata: ['follow_up_id' => $followUp->id, 'feedback_appended' => $feedback],
            ));

            return $followUp->load(['assignedAdmin', 'histories.performedBy']);
        });
    }
}
