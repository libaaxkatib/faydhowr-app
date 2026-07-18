<?php

namespace App\Exceptions\Auth;

use RuntimeException;

class OtpRateLimitedException extends RuntimeException
{
    public static function create(): self
    {
        return new self('Too many verification code requests. Please try again later.');
    }
}
