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

class CompleteFollowUpAction
{
    public function handle(FollowUp $followUp, ?string $note, Admin $actor): FollowUp
    {
        return DB::transaction(function () use ($followUp, $note, $actor) {
            $followUp->update(['status' => FollowUpStatus::Completed]);

            FollowUpHistory::query()->create([
                'follow_up_id' => $followUp->id,
                'action' => FollowUpActionEnum::Completed,
                'note' => $note,
                'performed_by' => $actor->id,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Follow-up #{$followUp->id} marked completed.",
                entityType: FollowUp::class,
                entityId: $followUp->id,
            ));

            return $followUp->load(['assignedAdmin', 'histories.performedBy']);
        });
    }
}
