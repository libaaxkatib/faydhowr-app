<?php

namespace App\Enums;

/**
 * Where one facility stands for ONE calendar month of the monthly Deep Cleaning requirement, from recorded completions only (nothing is
 * assumed, estimated or backfilled). The four states never overlap: a facility with a completion in the month is Completed whatever came before.
 */
enum FacilityDeepCleaningState: string
{
    /** A counted completion exists with its completion date inside the selected month. */
    case Completed = 'completed';
    /** No completion in the month; the last one was in the previous month (or, for a month still running, it is simply not done yet). */
    case NotCompleted = 'not_completed';
    /** No completion in the month and none in at least the previous month either: the facility has gone several consecutive months without one. */
    case OverdueMultiple = 'overdue_multiple';
    /** No completion has ever been recorded for the facility (up to the end of the selected month). */
    case NoHistory = 'no_history';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Completed this month',
            self::NotCompleted => 'Not completed this month',
            self::OverdueMultiple => 'Overdue for several months',
            self::NoHistory => 'No Deep Cleaning recorded',
        };
    }

    /** Sort order for lists: the facilities that need follow-up first. */
    public function urgency(): int
    {
        return match ($this) {
            self::OverdueMultiple => 0,
            self::NoHistory => 1,
            self::NotCompleted => 2,
            self::Completed => 3,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
