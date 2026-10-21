<?php

namespace App\Enums;

/** Staff complaints (raised by the people working at the site) and customer complaints (raised by the client) are kept apart. */
enum FacilityComplaintType: string
{
    case Staff = 'staff';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staff complaint',
            self::Customer => 'Customer complaint',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
