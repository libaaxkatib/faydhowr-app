<?php

namespace App\Enums;

enum LocationType: string
{
    case Client = 'client';
    case Office = 'office';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Client Company',
            self::Office => 'Fayadhowr Office',
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
