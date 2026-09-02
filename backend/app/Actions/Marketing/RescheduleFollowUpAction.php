<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Enums\Marketing\FollowUpAction as FollowUpActionEnum;
use App\Enums\Marketing\FollowUpStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\FollowUp;
use App\Models\FollowUpHistory;
use Illuminate\Support\Facades\DB;

class RescheduleFollowUpAction
{
    public function handle(FollowUp $followUp, string $newDate, ?string $note, Admin $actor): FollowUp
    {
        return DB::transaction(function () use ($followUp, $newDate, $note, $actor) {
            $oldDate = $followUp->follow_up_date->toDateString();

            $followUp->update(['follow_up_date' => $newDate, 'status' => FollowUpStatus::Rescheduled]);

            FollowUpHistory::query()->create([
                'follow_up_id' => $followUp->id,
                'action' => FollowUpActionEnum::Rescheduled,
                'note' => $note ?? "Rescheduled from {$oldDate} to {$newDate}.",
                'performed_by' => $actor->id,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Follow-up #{$followUp->id} rescheduled to {$newDate}.",
                entityType: FollowUp::class,
                entityId: $followUp->id,
                metadata: ['old_date' => $oldDate, 'new_date' => $newDate],
            ));

            return $followUp->load(['assignedAdmin', 'histories.performedBy']);
        });
    }
}
