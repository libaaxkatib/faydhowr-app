<?php

namespace App\Exceptions\Review;

use RuntimeException;

class ReviewNotFoundException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Review not found.');
    }
}
