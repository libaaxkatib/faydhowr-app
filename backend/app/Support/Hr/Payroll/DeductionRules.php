<?php

namespace App\Support\Hr\Payroll;

use App\Support\Hr\Facility\FacilityCalendar;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The validation shared by every request that registers or corrects a penalty or a one-month advance (Release 1B; owner decisions P-1, A-1, U-1):
 * a positive USD amount with at most two decimals (at most 1,000,000), a real date from 2020-01-01 that is not in the future, a payroll month from 2020-01
 * up to next month, and a reason of 3-500 characters. One place, so the two kinds can never drift apart.
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
        // A real date from 2020-01-01 up to TODAY (owner decision, 2026-11-05, Option A): a penalty and an advance record something that already happened, so
        // a future date is refused. "Today" is the business calendar's (Mogadishu), the same day the screens and the payroll use.
        return [$required ? 'required' : 'sometimes', 'date', 'after_or_equal:2020-01-01', 'before_or_equal:'.FacilityCalendar::today()->toDateString()];
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
