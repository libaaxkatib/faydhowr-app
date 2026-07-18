<?php

namespace App\Exceptions\Customer;

use RuntimeException;

class CustomerOperationDeniedException extends RuntimeException
{
    public static function inactiveOrBlocked(): self
    {
        return new self('Customer account cannot use customer services in its current status.');
    }
}
