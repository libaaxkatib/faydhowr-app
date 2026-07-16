<?php

namespace App\Actions\Booking;

use App\Models\Booking;
use App\Models\CustomerProfile;

class GetCustomerBookingAction
{
    public function handle(CustomerProfile $profile, int $bookingId): ?Booking
    {
        return $profile
            ->bookings()
            ->with(['service', 'serviceMode'])
            ->whereKey($bookingId)
            ->first();
    }
}
