<?php

namespace App\Support\Hr\Payroll;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The validation shared by every request that registers or corrects a penalty or a one-month advance (Release 1B; owner decisions P-1, A-1, U-1):
 * a positive USD amount with at most two decimals, a real date (not absurdly far ahead), a payroll month that exists (not later than next month), and a reason a person can read later. One place, so the two kinds can never drift apart.
 */
final class DeductionRules
{
    /** Largest single amount accepted (a typing mistake guard, not a business limit). */
    public const MAX_AMOUNT = 1000000;

    public static function normalize(FormRequest $request): void
    {
        $merge = [];

        if (is_string($request->input('currency'))) {
            $merge['currency'] = strtoupper(trim($request->input('currency')));
        }

        if (is_string($request->input('reason'))) {
            $merge['reason'] = trim($request->input('reason'));
        }

        if ($merge !== []) {
            $request->merge($merge);
        }
    }

    /** @return list<mixed> */
    public static function amount(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'numeric', 'gt:0', 'max:'.self::MAX_AMOUNT, 'regex:/^\d+(\.\d{1,2})?$/'];
    }

    /** @return list<mixed> */
    public static function currency(): array
    {
        return ['required', 'string', 'in:USD'];
    }

    /** @return list<mixed> */
    public static function date(bool $required = true): array
    {
        // A real date: not before 2020 and not more than a month ahead (a typo guard - a penalty dated in 2062 is a mistake, one dated next week is not).
        return [$required ? 'required' : 'sometimes', 'date', 'after_or_equal:2020-01-01', 'before_or_equal:'.now()->addDays(31)->toDateString()];
    }

    /** @return list<mixed> */
    public static function reason(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'min:3', 'max:500'];
    }

    /** @return list<mixed> */
    public static function idempotencyKey(): array
    {
        return ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\-]+$/'];
    }

    /** @return list<mixed> */
    public static function period(bool $required = true): array
    {
        return [
            $required ? 'required' : 'sometimes', 'bail', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value < '2020-01') {
                    $fail('The payroll month is too far in the past.');
                } elseif ($value > now()->addMonthNoOverflow()->format('Y-m')) {
                    $fail('The payroll month cannot be later than next month.');
                }
            },
        ];
    }
}
