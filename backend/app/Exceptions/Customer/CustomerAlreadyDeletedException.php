<?php

namespace App\Exceptions\Customer;

use RuntimeException;

class CustomerAlreadyDeletedException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Customer is already deleted.');
    }
}
