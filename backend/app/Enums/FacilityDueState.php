<?php

namespace App\Enums;

/**
 * Where a company's or workplace's inspection schedule stands TODAY, computed from stored inspections and the schedule only. There is no
 * scheduler or queue worker in production, so this is evaluated whenever the panel asks - it is a display state, not a delivered reminder.
 */
enum FacilityDueState: string
{
    case NeverInspected = 'never_inspected';
    case Overdue = 'overdue';
    case Due = 'due';
    case DueSoon = 'due_soon';
    case Ok = 'ok';
    /** Inspected at least once, but neither a frequency nor a follow-up date exists, so no next date can be given. */
    case NoSchedule = 'no_schedule';

    public function label(): string
    {
        return match ($this) {
            self::NeverInspected => 'Never inspected',
            self::Overdue => 'Overdue',
            self::Due => 'Due',
            self::DueSoon => 'Due soon',
            self::Ok => 'Up to date',
            self::NoSchedule => 'No schedule set',
        };
    }

    /** Sort order for lists: the most urgent first. */
    public function urgency(): int
    {
        return match ($this) {
            self::Overdue => 0,
            self::Due => 1,
            self::NeverInspected => 2,
            self::DueSoon => 3,
            self::NoSchedule => 4,
            self::Ok => 5,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
