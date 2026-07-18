<?php

namespace App\Actions\Quotation;

use App\Models\Quotation;

class GetAdminQuotationAction
{
    public function handle(int $quotationId): ?Quotation
    {
        return Quotation::query()
            ->with([
                'customerProfile',
                'booking',
                'assignedAdmin',
                'latestRevision.issuedByAdmin',
                'revisions' => fn ($query) => $query->with('issuedByAdmin')->orderByDesc('version_number'),
                'attachments.upload',
                'discussionMessages' => fn ($query) => $query->with('attachments.upload')->oldest()->oldest('id'),
                'statusHistories' => fn ($query) => $query->oldest()->oldest('id'),
            ])
            ->whereKey($quotationId)
            ->first();
    }
}
