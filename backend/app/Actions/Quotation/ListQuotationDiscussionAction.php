<?php

namespace App\Actions\Quotation;

use App\Models\CustomerProfile;
use App\Models\QuotationDiscussionMessage;
use Illuminate\Database\Eloquent\Collection;

class ListQuotationDiscussionAction
{
    /**
     * @return Collection<int, QuotationDiscussionMessage>
     */
    public function handle(CustomerProfile $profile, int $quotationId): ?Collection
    {
        $quotation = $profile
            ->quotations()
            ->whereKey($quotationId)
            ->first();

        if ($quotation === null) {
            return null;
        }

        return $quotation
            ->discussionMessages()
            ->with('attachments.upload')
            ->oldest()
            ->get();
    }
}
