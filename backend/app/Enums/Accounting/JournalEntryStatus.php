<?php

namespace App\Enums\Accounting;

use App\Enums\Accounting\Concerns\InteractsWithValues;

enum JournalEntryStatus: string
{
    use InteractsWithValues;

    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::Cancelled => 'Cancelled',
        };
    }
}
