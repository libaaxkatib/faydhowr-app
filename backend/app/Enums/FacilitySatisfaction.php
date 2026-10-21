<?php

namespace App\Enums;

/** Explicitly documented feedback about how a complaint was handled. It is never inferred from a status or from the existence of a response. */
enum FacilitySatisfaction: string
{
    case Satisfied = 'satisfied';
    case NotSatisfied = 'not_satisfied';

    public function label(): string
    {
        return match ($this) {
            self::Satisfied => 'Satisfied (documented)',
            self::NotSatisfied => 'Not satisfied (documented)',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
