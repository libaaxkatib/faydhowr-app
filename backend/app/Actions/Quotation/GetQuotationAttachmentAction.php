<?php

namespace App\Actions\Quotation;

use App\Models\CustomerProfile;
use App\Models\Upload;

/**
 * Resolves a quotation attachment's upload owner-scoped for streaming.
 * Storage paths are never exposed; the file is streamed by the Upload Service.
 */
class GetQuotationAttachmentAction
{
    public function handle(CustomerProfile $profile, int $quotationId, int $attachmentId): ?Upload
    {
        $quotation = $profile
            ->quotations()
            ->whereKey($quotationId)
            ->first();

        return $quotation
            ?->attachments()
            ->whereKey($attachmentId)
            ->with('upload')
            ->first()
            ?->upload;
    }
}
