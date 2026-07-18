<?php

namespace App\Actions\Quotation;

use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListCustomerQuotationsAction
{
    /**
     * @return LengthAwarePaginator<int, Quotation>
     */
    public function handle(
        CustomerProfile $profile,
        ?QuotationStatus $status,
        ?int $bookingId,
        int $perPage,
    ): LengthAwarePaginator {
        return $profile
            ->quotations()
            ->with(['booking', 'latestRevision'])
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->when($bookingId !== null, fn ($query) => $query->where('booking_id', $bookingId))
            ->latest()
            ->paginate($perPage);
    }
}
