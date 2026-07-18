<?php

namespace App\Enums;

enum QuotationRevisionSource: string
{
    case AdminIssue = 'admin_issue';
    case SystemMigration = 'system_migration';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
