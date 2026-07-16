<?php

namespace App\Enums;

enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Manager = 'manager';
    case Sales = 'sales';
    case Inventory = 'inventory';
    case Accountant = 'accountant';

    /**
     * Roles that may have persisted permission assignments.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $role): bool => $role !== self::SuperAdmin,
        ));
    }

    /**
     * @return list<string>
     */
    public static function assignableValues(): array
    {
        return array_map(
            fn (self $role): string => $role->value,
            self::assignable(),
        );
    }
}
