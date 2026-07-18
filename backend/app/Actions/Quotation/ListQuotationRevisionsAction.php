<?php

namespace App\Actions\Quotation;

use App\Models\CustomerProfile;
use App\Models\Quotation;
use App\Models\QuotationRevision;
use Illuminate\Database\Eloquent\Collection;

/**
 * Returns the full immutable revision chain (Version 1, 2, 3…) read-only.
 */
class ListQuotationRevisionsAction
{
    /**
     * @return array{quotation: Quotation, revisions: Collection<int, QuotationRevision>}|null
     */
    public function handle(CustomerProfile $profile, int $quotationId): ?array
    {
        $quotation = $profile
            ->quotations()
            ->whereKey($quotationId)
            ->first();

        if ($quotation === null) {
            return null;
        }

        $revisions = $quotation
            ->revisions()
            ->with('issuedByAdmin')
            ->orderByDesc('version_number')
            ->get()
            ->each(fn ($revision) => $revision->setRelation('quotation', $quotation));

        return [
            'quotation' => $quotation,
            'revisions' => $revisions,
        ];
    }
}
