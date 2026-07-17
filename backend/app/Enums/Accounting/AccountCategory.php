<?php

namespace App\Enums\Accounting;

use App\Enums\Accounting\Concerns\InteractsWithValues;

enum AccountCategory: string
{
    use InteractsWithValues;

    case Assets = 'assets';
    case Liabilities = 'liabilities';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expenses = 'expenses';

    public function label(): string
    {
        return match ($this) {
            self::Assets => 'Assets',
            self::Liabilities => 'Liabilities',
            self::Equity => 'Equity',
            self::Revenue => 'Revenue',
            self::Expenses => 'Expenses',
        };
    }
}
