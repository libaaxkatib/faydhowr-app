<?php

namespace App\Actions\Quotation;

use App\Models\CustomerProfile;
use App\Models\Quotation;

class GetCustomerQuotationAction
{
    public function handle(CustomerProfile $profile, int $quotationId): ?Quotation
    {
        return $profile
            ->quotations()
            ->with(['booking', 'latestRevision', 'attachments.upload'])
            ->whereKey($quotationId)
            ->first();
    }
}
