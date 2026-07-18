<?php

namespace App\Actions\Booking;

use App\DataTransferObjects\Booking\AdminBookingFiltersData;
use App\Models\Booking;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListAdminBookingsAction
{
    /**
     * @return LengthAwarePaginator<int, Booking>
     */
    public function handle(AdminBookingFiltersData $filters): LengthAwarePaginator
    {
        return Booking::query()
            ->with(['customerProfile', 'service', 'serviceMode'])
            ->when($filters->status, fn ($query) => $query->where('status', $filters->status->value))
            ->when($filters->serviceId, fn ($query) => $query->where('service_id', $filters->serviceId))
            ->when($filters->customerProfileId, fn ($query) => $query->where('customer_profile_id', $filters->customerProfileId))
            ->when($filters->from, fn ($query) => $query->whereDate('requested_date', '>=', $filters->from))
            ->when($filters->to, fn ($query) => $query->whereDate('requested_date', '<=', $filters->to))
            ->when($filters->search, fn ($query) => $query->where('booking_number', 'like', '%'.$filters->search.'%'))
            ->latest('id')
            ->paginate($filters->perPage);
    }
}
