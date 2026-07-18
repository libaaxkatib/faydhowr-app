<?php

namespace App\Actions\Quotation;

use App\Models\CustomerProfile;
use App\Models\Quotation;
use App\Models\QuotationStatusHistory;
use Illuminate\Database\Eloquent\Collection;

/**
 * Ordered lifecycle events keyed by the permanent Quotation Number
 * (API Design §9.7).
 */
class GetQuotationTimelineAction
{
    /**
     * @return array{quotation: Quotation, events: Collection<int, QuotationStatusHistory>}|null
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

        return [
            'quotation' => $quotation,
            'events' => $quotation
                ->statusHistories()
                ->oldest()
                ->oldest('id')
                ->get(),
        ];
    }
}
