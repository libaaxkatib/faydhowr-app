<?php

namespace App\Exceptions\Review;

use RuntimeException;

class ReviewAlreadyExistsException extends RuntimeException
{
    public static function make(): self
    {
        return new self('This booking has already been reviewed.');
    }
}
