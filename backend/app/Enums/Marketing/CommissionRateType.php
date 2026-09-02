<?php

namespace App\Enums\Marketing;

enum CommissionRateType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
