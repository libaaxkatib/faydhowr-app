<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Enums\Marketing\FollowUpAction as FollowUpActionEnum;
use App\Enums\Marketing\FollowUpStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\FollowUp;
use App\Models\FollowUpHistory;
use App\Models\MarketingRecord;
use Illuminate\Support\Facades\DB;

class CreateFollowUpAction
{
    public function handle(MarketingRecord $record, array $data, Admin $actor): FollowUp
    {
        return DB::transaction(function () use ($record, $data, $actor) {
            $followUp = FollowUp::query()->create([
                'marketing_record_id' => $record->id,
                'follow_up_date' => $data['follow_up_date'],
                'status' => FollowUpStatus::Scheduled,
                'assigned_admin_id' => $data['assigned_admin_id'] ?? $record->assigned_admin_id,
                'created_by' => $actor->id,
            ]);

            FollowUpHistory::query()->create([
                'follow_up_id' => $followUp->id,
                'action' => FollowUpActionEnum::Created,
                'note' => null,
                'performed_by' => $actor->id,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: "Follow-up scheduled for '{$record->record_number}' on {$data['follow_up_date']}.",
                entityType: FollowUp::class,
                entityId: $followUp->id,
                metadata: ['marketing_record_id' => $record->id],
            ));

            return $followUp->load(['assignedAdmin', 'histories.performedBy']);
        });
    }
}
