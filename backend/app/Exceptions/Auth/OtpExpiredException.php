<?php

namespace App\Exceptions\Auth;

use RuntimeException;

class OtpExpiredException extends RuntimeException
{
    public static function create(): self
    {
        return new self('The verification code has expired. Please request a new one.');
    }
}
