<?php

namespace App\Actions\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\CustomerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListCustomerBookingsAction
{
    /**
     * @return LengthAwarePaginator<int, Booking>
     */
    public function handle(
        CustomerProfile $profile,
        ?BookingStatus $status,
        ?int $serviceId,
        int $perPage,
    ): LengthAwarePaginator {
        return $profile
            ->bookings()
            ->with(['service', 'serviceMode'])
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->when($serviceId !== null, fn ($query) => $query->where('service_id', $serviceId))
            ->latest()
            ->paginate($perPage);
    }
}
