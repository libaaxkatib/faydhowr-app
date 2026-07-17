<?php

namespace App\Enums\Settings;

enum BranchStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case ComingSoon = 'COMING_SOON';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::ComingSoon => 'Coming Soon',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
