<?php

namespace App\Enums\Accounting;

use App\Enums\Accounting\Concerns\InteractsWithValues;

enum AccountStatus: string
{
    use InteractsWithValues;

    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }
}
