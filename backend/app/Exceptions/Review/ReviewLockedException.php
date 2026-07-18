<?php

namespace App\Exceptions\Review;

use RuntimeException;

class ReviewLockedException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Published or hidden reviews can no longer be edited or deleted.');
    }
}
