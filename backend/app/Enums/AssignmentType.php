<?php

namespace App\Enums;

/**
 * What kind of place an employee holds in a company: the regular (Permanent) holder, or a Replacement covering for someone
 * else. It is always an explicit choice - never inferred from the fact that an assignment follows another one. A Replacement can
 * later be made Permanent by editing the assignment; it cannot be "returned" once permanent.
 */
enum AssignmentType: string
{
    case Permanent = 'permanent';
    case Replacement = 'replacement';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
