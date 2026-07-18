<?php

namespace App\DataTransferObjects\Booking;

use App\Enums\BookingStatus;

final readonly class AdminBookingFiltersData
{
    public function __construct(
        public ?BookingStatus $status,
        public ?int $serviceId,
        public ?int $customerProfileId,
        public ?string $from,
        public ?string $to,
        public ?string $search,
        public int $perPage,
    ) {}
}
