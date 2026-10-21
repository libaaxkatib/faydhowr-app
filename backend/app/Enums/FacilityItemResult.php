<?php

namespace App\Enums;

/** The two outcomes of one inspected item. An item that does not apply at that site has no result at all (it is marked not applicable). */
enum FacilityItemResult: string
{
    case Satisfactory = 'satisfactory';
    case Unsatisfactory = 'unsatisfactory';

    public function label(): string
    {
        return match ($this) {
            self::Satisfactory => 'Clean / satisfactory',
            self::Unsatisfactory => 'Dirty / unsatisfactory',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
