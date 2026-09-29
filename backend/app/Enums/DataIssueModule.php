<?php

namespace App\Enums;

/**
 * Which part of the system an issue belongs to. Starts with the modules
 * that exist today; add a case here (+label()) when a new module needs its
 * own bucket rather than 'other' — this enum is expected to grow over time.
 */
enum DataIssueModule: string
{
    case Hr = 'hr';
    case Marketing = 'marketing';
    case Finance = 'finance';
    case Bookings = 'bookings';
    case Customers = 'customers';
    case Quotations = 'quotations';
    case Store = 'store';
    case System = 'system';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Hr => 'HR',
            self::Marketing => 'Marketing',
            self::Finance => 'Finance',
            self::Bookings => 'Bookings',
            self::Customers => 'Customers',
            self::Quotations => 'Quotations',
            self::Store => 'Store',
            self::System => 'System',
            self::Other => 'Other',
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
