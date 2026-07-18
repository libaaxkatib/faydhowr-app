<?php

namespace App\Exceptions\Auth;

use RuntimeException;

class OtpCooldownException extends RuntimeException
{
    public static function create(): self
    {
        return new self('Please wait before requesting another code.');
    }
}
