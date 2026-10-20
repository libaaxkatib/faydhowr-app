<?php

namespace App\Enums;

/**
 * The shift of ONE work assignment (a person can work a day shift at one company and a night shift at another, so it belongs to the
 * assignment, never to the employee). It is information only: it does not change salary, dates, type or company. An assignment whose
 * shift was never recorded has NULL - nothing is assumed for older assignments.
 */
enum AssignmentShift: string
{
    case Day = 'day';
    case Night = 'night';

    public function label(): string
    {
        return match ($this) {
            self::Day => 'Day shift',
            self::Night => 'Night shift',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
