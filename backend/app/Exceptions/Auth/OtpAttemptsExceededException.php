<?php

namespace App\Exceptions\Auth;

use RuntimeException;

class OtpAttemptsExceededException extends RuntimeException
{
    public static function create(): self
    {
        return new self('Too many failed attempts. Please request a new verification code.');
    }
}
