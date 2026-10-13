<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

/**
 * Shared format rule for the optional employee payment/account number: letters, digits, spaces and the
 * punctuation found in account numbers (- + . /). No markup, quotes or control characters.
 */
final class EmployeePaymentAccountRule
{
    public const PATTERN = '/^[\p{L}\p{N} \-+.\/]+$/u';

    public static function isValid(string $value): bool
    {
        return mb_strlen($value) <= 50 && preg_match(self::PATTERN, $value) === 1;
    }
}
