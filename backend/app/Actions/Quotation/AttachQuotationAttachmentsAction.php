<?php

namespace App\Actions\Quotation;

use App\Enums\QuotationStatus;
use App\Exceptions\Quotation\QuotationAttachmentsLockedException;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use App\Models\Upload;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Attaches staged uploads to a quotation request by UUID (Sprint 28).
 * Allowed only while `draft`; after Submit the attachment set is permanently
 * immutable and additional files travel only through discussion.
 */
class AttachQuotationAttachmentsAction
{
    public function __construct(private ResolveQuotationUploadsAction $resolveUploads) {}

    /**
     * @param  list<string>  $uploadUuids
     */
    public function handle(CustomerProfile $profile, int $quotationId, array $uploadUuids): Quotation
    {
        return DB::transaction(function () use ($profile, $quotationId, $uploadUuids): Quotation {
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

            $this->resolveUploads
                ->handle($profile, $uploadUuids)
                ->each(function (Upload $upload) use ($quotation): void {
                    $quotation->attachments()->create(['upload_id' => $upload->id]);
                    $upload->update(['attached_at' => now()]);
                });

            return $quotation->load(['booking', 'attachments.upload']);
        });
    }
}
