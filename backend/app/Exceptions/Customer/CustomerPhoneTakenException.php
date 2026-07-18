<?php

namespace App\Exceptions\Customer;

use RuntimeException;

class CustomerPhoneTakenException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Phone number already belongs to another customer.');
    }
}
