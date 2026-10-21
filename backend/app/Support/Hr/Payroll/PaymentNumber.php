<?php

namespace App\Support\Hr\Payroll;

use App\Models\Employee;

/**
 * The one place that decides which number a payment list shows for an employee, where it came from, and whether an imported number
 * agrees with the registration phone. Four things are kept apart and never merged: the internal employee id, the employee number in
 * the Employee Register (EMP-…), the registration phone, and the payroll/payment number imported from the Excel sources.
 *
 * Precedence (owner decision 6):
 *  1. the stored payroll/payment number (imported from the workbooks or typed by HR) - always wins, whether or not it agrees with the phone;
 *  2. only when no number is stored: the registration phone, clearly labelled as a fallback;
 *  3. otherwise: missing - never guessed.
 * Nothing here writes anything.
 */
final class PaymentNumber
{
    public const SOURCE_WORKBOOK_PART_ONE = 'workbook_part_one';

    public const SOURCE_WORKBOOK_PART_TWO = 'workbook_part_two';

    public const SOURCE_MANUAL = 'manual';

    public const CHECK_MATCH = 'match';

    public const CHECK_MISMATCH = 'mismatch';

    public const CHECK_ACCEPTED = 'accepted';

    public const LABEL_IMPORTED = 'Imported from the Excel source';

    public const LABEL_RECORDED = 'Recorded payment number';

    public const LABEL_PHONE_FALLBACK = 'Registration phone (fallback - no payment number recorded)';

    public const LABEL_MISSING = 'Missing';

    /** Digits only, so "+252 61-234 5678", "0612345678" and "612345678" can be compared. */
    public static function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    /**
     * Does an imported number agree with the registration phone (or the alternate phone)? Compared on the last nine digits, because the
     * same Somali mobile number is written with and without the country code or a leading zero. Shorter numbers must match exactly.
     */
    public static function agreesWithRegister(?string $number, ?string $phone, ?string $alternatePhone = null): bool
    {
        $n = self::digits($number);

        if ($n === '') {
            return false;
        }

        foreach ([$phone, $alternatePhone] as $candidate) {
            $c = self::digits($candidate);

            if ($c === '') {
                continue;
            }

            if ($n === $c) {
                return true;
            }

            if (strlen($n) >= 8 && strlen($c) >= 8 && substr($n, -9) === substr($c, -9)) {
                return true;
            }
        }

        return false;
    }

    /** 'match' or 'mismatch' for an imported number against the employee's registration phones. */
    public static function registerCheck(?string $number, ?string $phone, ?string $alternatePhone = null): string
    {
        return self::agreesWithRegister($number, $phone, $alternatePhone) ? self::CHECK_MATCH : self::CHECK_MISMATCH;
    }

    /**
     * What a payment list shows for this employee.
     *
     * @return array{number: ?string, label: string, is_fallback: bool, needs_review: bool}
     */
    public static function resolve(Employee $employee): array
    {
        $stored = self::clean($employee->payment_account_number);

        if ($stored !== null) {
            $imported = in_array($employee->payment_account_source, [self::SOURCE_WORKBOOK_PART_ONE, self::SOURCE_WORKBOOK_PART_TWO], true);

            return [
                'number' => $stored,
                'label' => $imported ? self::LABEL_IMPORTED : self::LABEL_RECORDED,
                'is_fallback' => false,
                'needs_review' => $employee->payment_account_register_check === self::CHECK_MISMATCH,
            ];
        }

        $phone = self::clean($employee->phone);

        if ($phone !== null) {
            return ['number' => $phone, 'label' => self::LABEL_PHONE_FALLBACK, 'is_fallback' => true, 'needs_review' => false];
        }

        return ['number' => null, 'label' => self::LABEL_MISSING, 'is_fallback' => false, 'needs_review' => false];
    }

    /** Trimmed text, or null when empty. The value is never converted to a number. */
    public static function clean(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }
}
