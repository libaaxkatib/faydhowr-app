<?php

namespace App\Exceptions\Auth;

use RuntimeException;

class AccountRestrictedException extends RuntimeException
{
    public static function create(): self
    {
        return new self('This account cannot sign in.');
    }
}
