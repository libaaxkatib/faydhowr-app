<?php

namespace App\Enums\Customer;

enum CustomerStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Blocked = 'BLOCKED';
    case Deleted = 'DELETED';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Blocked => 'Blocked',
            self::Deleted => 'Deleted',
        };
    }

    /**
     * Status values stored on customer_profiles.status (DELETED is soft-delete).
     *
     * @return list<string>
     */
    public static function persistedValues(): array
    {
        return [
            self::Active->value,
            self::Inactive->value,
            self::Blocked->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Status targets allowed when restoring a soft-deleted customer.
     *
     * @return list<string>
     */
    public static function restoreValues(): array
    {
        return [
            self::Active->value,
            self::Inactive->value,
        ];
    }
}
