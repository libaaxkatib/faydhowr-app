<?php

namespace App\Events\Booking;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingScheduled
{
    use Dispatchable, SerializesModels;

    public function __construct(public Booking $booking) {}
}
