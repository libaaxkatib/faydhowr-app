<?php

namespace App\Enums\Marketing;

/**
 * docs/HRM_MARKETING_SRS.md §9.1: company is never mandatory — a PROJECT's
 * responsible party may be a Company, Engineer, Owner/Individual, or Other.
 */
enum ResponsiblePartyType: string
{
    case Company = 'company';
    case Engineer = 'engineer';
    case Owner = 'owner';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
