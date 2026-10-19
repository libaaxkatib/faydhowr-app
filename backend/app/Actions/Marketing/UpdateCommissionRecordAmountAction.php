<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Enums\Marketing\CommissionStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\CommissionRecord;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Records a manually agreed commission amount. Approved and paid entries are
 * closed and are not edited here; no rate or formula is applied.
 */
class UpdateCommissionRecordAmountAction
{
    public function handle(CommissionRecord $record, array $data, Admin $actor): CommissionRecord
    {
        if (in_array($record->status, [CommissionStatus::Approved, CommissionStatus::Paid], true)) {
            throw new HttpResponseException(
                ApiResponse::error('An approved or paid commission entry can no longer be changed.', 'COMMISSION_RECORD_CLOSED', 422),
            );
        }

        $before = ['amount' => $record->amount, 'status' => $record->status->value];

        $record->update([
            'amount' => $data['amount'],
            'status' => CommissionStatus::Recorded,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $record->notes,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Commission entry #{$record->id} amount recorded manually.",
            entityType: CommissionRecord::class,
            entityId: $record->id,
            metadata: ['before' => $before, 'after' => ['amount' => $record->amount, 'status' => CommissionStatus::Recorded->value]],
        ));

        return $record->load(['admin', 'marketingRecord']);
    }
}
