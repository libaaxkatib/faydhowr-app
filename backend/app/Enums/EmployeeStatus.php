<?php

namespace App\Enums;

/**
 * Mirrors the recruitment pipeline in docs/HRM_MARKETING_SRS.md §24/§27:
 * Applicant -> Recruitment -> Practical -> Waiting -> Approved -> Active,
 * plus a terminal Inactive state. Recruitment/Practical/Waiting are status-
 * filtered views of one employee record, not separate entities.
 */
enum EmployeeStatus: string
{
    case Applicant = 'applicant';
    case Recruitment = 'recruitment';
    case Practical = 'practical';
    case Waiting = 'waiting';
    case Approved = 'approved';
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Applicant => 'Applicant',
            self::Recruitment => 'Recruitment',
            self::Practical => 'Practical',
            self::Waiting => 'Waiting',
            self::Approved => 'Approved',
            self::Active => 'Active',
            self::Inactive => 'Inactive',
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
