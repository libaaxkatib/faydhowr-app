<?php

namespace App\Enums;

/**
 * docs/HRM_MARKETING_SRS.md HR §8. Only 'confirmed' unlocks Training - the
 * explicit business rule for this phase.
 */
enum EmployeeUniformStatus: string
{
    case Pending = 'pending';
    case Purchased = 'purchased';
    case Received = 'received';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Purchased => 'Purchased',
            self::Received => 'Received',
            self::Confirmed => 'Confirmed',
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
