<?php

namespace App\Exceptions\Settings;

use App\Models\Branch;
use RuntimeException;

class BranchNotActiveException extends RuntimeException
{
    public static function forDefault(Branch $branch): self
    {
        return new self(sprintf(
            'Branch "%s" cannot be set as default because its status is %s; only ACTIVE branches can be the default.',
            $branch->code,
            $branch->status->value,
        ));
    }
}
