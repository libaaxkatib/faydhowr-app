<?php

namespace App\Support\Hr\Payroll;

use App\Models\PayrollRun;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A payroll month that has been approved or finalized is locked: its attendance, penalty, leave and workday inputs may no longer
 * change. Assignment and salary changes that reach a closed month are handled by ClosedPayrollCorrection (Super Admin only, with a
 * reason, the closed month never rewritten), so a finalized month always stays reproducible.
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
}
