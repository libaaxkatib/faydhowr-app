<?php

namespace App\Actions\Quotation;

use App\Enums\QuotationStatus;
use App\Exceptions\Quotation\QuotationAttachmentsLockedException;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Detaches a request attachment while the quotation is still `draft`
 * (Sprint 28). The upload returns to staging (attached_at cleared) so the
 * regular staging expiry applies again.
 */
class DetachQuotationAttachmentAction
{
    public function handle(CustomerProfile $profile, int $quotationId, int $attachmentId): Quotation
    {
        return DB::transaction(function () use ($profile, $quotationId, $attachmentId): Quotation {
            $quotation = $profile
                ->quotations()
                ->whereKey($quotationId)
                ->lockForUpdate()
                ->first();

            if ($quotation === null) {
                throw new ModelNotFoundException;
            }

            if ($quotation->status !== QuotationStatus::Draft) {
                throw QuotationAttachmentsLockedException::make();
            }

            $attachment = $quotation
                ->attachments()
                ->whereKey($attachmentId)
                ->with('upload')
                ->lockForUpdate()
                ->first();

            if ($attachment === null) {
                throw new ModelNotFoundException;
            }

            $upload = $attachment->upload;
            $attachment->delete();
            $upload?->update(['attached_at' => null]);

            return $quotation->load(['booking', 'attachments.upload']);
        });
    }
}
