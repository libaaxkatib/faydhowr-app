<?php

namespace App\Exceptions\Auth;

use RuntimeException;

class OtpInvalidException extends RuntimeException
{
    public static function create(): self
    {
        return new self('The verification code is invalid.');
    }
}
