<?php

namespace App\Enums\Accounting;

use App\Enums\Accounting\Concerns\InteractsWithValues;

enum AccountingPeriodStatus: string
{
    use InteractsWithValues;

    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
        };
    }
}
