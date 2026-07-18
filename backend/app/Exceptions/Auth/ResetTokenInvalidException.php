<?php

namespace App\Exceptions\Auth;

use RuntimeException;

class ResetTokenInvalidException extends RuntimeException
{
    public static function create(): self
    {
        return new self('The password reset token is invalid or has expired.');
    }
}
