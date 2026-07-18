<?php

namespace App\Exceptions\Customer;

use RuntimeException;

class CustomerInvalidStatusException extends RuntimeException
{
    public static function forValue(string $status): self
    {
        return new self("Invalid customer status [{$status}].");
    }

    public static function restoreTarget(string $status): self
    {
        return new self("Deleted customers may only be restored to ACTIVE or INACTIVE, not [{$status}].");
    }
}
