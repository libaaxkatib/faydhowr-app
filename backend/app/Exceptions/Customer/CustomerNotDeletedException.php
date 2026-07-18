<?php

namespace App\Exceptions\Customer;

use RuntimeException;

class CustomerNotDeletedException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Customer is not deleted and cannot be restored.');
    }
}
