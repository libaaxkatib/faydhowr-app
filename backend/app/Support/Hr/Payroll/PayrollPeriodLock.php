<?php

namespace App\Support\Hr\Payroll;

use App\Models\PayrollRun;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A payroll month that has been approved or finalized is locked: its inputs
 * (salary history, verified replacement workdays) may no longer change. Any
 * correction must go through a payroll adjustment that applies in a later
 * period, so a finalized month always stays reproducible.
 */
final class PayrollPeriodLock
{
    public const LOCKED_STATUSES = ['approved', 'finalized'];

    public function isLocked(string $period): bool
    {
        return PayrollRun::query()
            ->where('period', $period)
            ->whereIn('status', self::LOCKED_STATUSES)
            ->exists();
    }

    /**
     * Whether any locked payroll month falls on or after the month of $date.
     */
    public function isLockedFrom(string $date): bool
    {
        return PayrollRun::query()
            ->where('period', '>=', substr($date, 0, 7))
            ->whereIn('status', self::LOCKED_STATUSES)
            ->exists();
    }

    public function assertDateEditable(string $date, string $what): void
    {
        if ($this->isLocked(substr($date, 0, 7))) {
            throw new HttpResponseException(ApiResponse::error(
                "{$what} falls in payroll month ".substr($date, 0, 7).' which is approved or finalized. Record a payroll adjustment instead.',
                'PAYROLL_PERIOD_LOCKED',
                422,
            ));
        }
    }

    public function assertSalaryChangeAllowed(string $effectiveFrom): void
    {
        if ($this->isLockedFrom($effectiveFrom)) {
            throw new HttpResponseException(ApiResponse::error(
                'This salary change takes effect in or before a payroll month that is approved or finalized. Record a payroll adjustment instead.',
                'PAYROLL_PERIOD_LOCKED',
                422,
            ));
        }
    }
}
