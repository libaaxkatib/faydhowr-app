<?php

namespace App\Enums\Customer;

enum AddressLabel: string
{
    case Home = 'Home';
    case Office = 'Office';
    case Other = 'Other';

    public function label(): string
    {
        return $this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
