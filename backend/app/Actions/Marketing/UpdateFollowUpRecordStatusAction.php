<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Enums\Marketing\FollowUpAction as FollowUpActionEnum;
use App\Enums\Marketing\MarketingRecordStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\FollowUp;
use App\Models\FollowUpHistory;
use App\Models\MarketingRecord;
use Illuminate\Support\Facades\DB;

/**
 * SRS §15 "Update status" reminder action, triggered from a follow-up.
 * Updates the same MarketingRecordStatus the record's own status endpoint
 * uses (no separate status set) and logs the change against the follow-up's
 * history in addition to the standard audit log.
 */
class UpdateFollowUpRecordStatusAction
{
    public function handle(FollowUp $followUp, MarketingRecordStatus $status, Admin $actor): FollowUp
    {
        return DB::transaction(function () use ($followUp, $status, $actor) {
            $record = $followUp->marketingRecord;
            $from = $record->status;

            $record->update(['status' => $status]);

            FollowUpHistory::query()->create([
                'follow_up_id' => $followUp->id,
                'action' => FollowUpActionEnum::StatusUpdated,
                'note' => "Status changed from {$from->value} to {$status->value}.",
                'performed_by' => $actor->id,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Marketing record '{$record->record_number}' status changed from {$from->value} to {$status->value} via follow-up #{$followUp->id}.",
                entityType: MarketingRecord::class,
                entityId: $record->id,
                metadata: ['follow_up_id' => $followUp->id, 'from_status' => $from->value, 'to_status' => $status->value],
            ));

            return $followUp->load(['assignedAdmin', 'histories.performedBy']);
        });
    }
}
