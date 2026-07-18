<?php

namespace App\Actions\Quotation;

use App\Enums\AuditAction;
use App\Enums\QuotationStatus;
use App\Events\Audit\AuditEvent;
use App\Exceptions\Quotation\QuotationInvalidStateException;
use App\Models\Admin;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;

/**
 * Optional signal (Sprint 28): `under_discussion` → `quotation_ready`.
 * Acceptance never requires closing the discussion first.
 */
class CloseQuotationDiscussionAction
{
    public function handle(Admin $admin, int $quotationId): Quotation
    {
        $quotation = DB::transaction(function () use ($admin, $quotationId): Quotation {
            $quotation = Quotation::query()
                ->whereKey($quotationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($quotation->status !== QuotationStatus::UnderDiscussion) {
                throw QuotationInvalidStateException::forAction('Only quotations under discussion can have their discussion closed.');
            }

            $quotation->update(['status' => QuotationStatus::QuotationReady]);

            $quotation->statusHistories()->create([
                'status' => QuotationStatus::QuotationReady,
                'changed_by_type' => 'admin',
                'changed_by_id' => $admin->id,
                'notes' => 'Discussion closed.',
            ]);

            return $quotation;
        });

        event(AuditEvent::record(
            action: AuditAction::QuotationCloseDiscussion,
            admin: $admin,
            description: 'Quotation discussion closed.',
            entityType: Quotation::class,
            entityId: $quotation->id,
            metadata: [
                'quotation_number' => $quotation->quotation_number,
                'previous_status' => QuotationStatus::UnderDiscussion->value,
                'new_status' => QuotationStatus::QuotationReady->value,
            ],
        ));

        return $quotation;
    }
}
