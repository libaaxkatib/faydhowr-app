<?php

namespace App\Exceptions\Review;

use RuntimeException;

class ReviewNotEligibleException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Only completed bookings can be reviewed.');
    }
}
