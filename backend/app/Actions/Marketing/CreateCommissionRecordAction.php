<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Enums\Marketing\CommissionStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\CommissionRecord;
use App\Models\MarketingRecord;

/**
 * Foundation only: logs that an employee brought this XARUN/PROJECT for
 * commission-tracking purposes. amount stays null and status stays
 * 'pending_calculation' — no formula is applied, per docs/HRM_MARKETING_SRS.md
 * §20-21/§39. A future, explicitly-approved calculation step will fill
 * amount/status in; nothing in this codebase does so yet.
 */
class CreateCommissionRecordAction
{
    public function handle(MarketingRecord $record, array $data, Admin $actor): CommissionRecord
    {
        $commissionRecord = CommissionRecord::query()->create([
            'admin_id' => $data['admin_id'],
            'marketing_record_id' => $record->id,
            'type' => $record->type,
            'reference_date' => $data['reference_date'],
            'commission_rate_id' => null,
            'amount' => null,
            'status' => CommissionStatus::PendingCalculation,
            'notes' => $data['notes'] ?? null,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Commission entry logged for marketing record '{$record->record_number}'.",
            entityType: CommissionRecord::class,
            entityId: $commissionRecord->id,
            metadata: ['marketing_record_id' => $record->id, 'admin_id' => $data['admin_id']],
        ));

        return $commissionRecord->load(['admin', 'marketingRecord']);
    }
}
