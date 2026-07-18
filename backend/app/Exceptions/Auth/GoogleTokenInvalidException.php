<?php

namespace App\Exceptions\Auth;

use RuntimeException;

class GoogleTokenInvalidException extends RuntimeException
{
    public static function create(): self
    {
        return new self('The Google sign-in token is invalid.');
    }
}
