<?php

namespace App\Exceptions\Customer;

use RuntimeException;

class CustomerEmailTakenException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Email already belongs to another customer.');
    }
}
