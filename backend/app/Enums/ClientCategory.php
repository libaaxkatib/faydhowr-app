<?php

namespace App\Enums;

/**
 * Whether a client record is a business/organization or a household. HR can
 * correct the category later without recreating the record.
 */
enum ClientCategory: string
{
    case Company = 'company';
    case Guri = 'guri';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Company',
            self::Guri => 'Guri (household)',
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
