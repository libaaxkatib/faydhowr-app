<?php

namespace App\Exceptions\Customer;

use RuntimeException;

class CustomerNotFoundException extends RuntimeException
{
    public static function forId(int $id): self
    {
        return new self("Customer [{$id}] was not found.");
    }
}
