<?php

namespace App\Exceptions\Review;

use RuntimeException;

class ReviewBookingNotFoundException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Booking not found.');
    }
}
