<?php

namespace App\Enums;

/**
 * docs/HRM_MARKETING_SRS.md HR §5: only Male/Female are approved. A third
 * value is a future management decision, not something to add speculatively.
 */
enum EmployeeGender: string
{
    case Male = 'male';
    case Female = 'female';

    public function label(): string
    {
        return match ($this) {
            self::Male => 'Male',
            self::Female => 'Female',
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
