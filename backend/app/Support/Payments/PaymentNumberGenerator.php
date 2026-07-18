<?php

namespace App\Support\Payments;

use App\Models\Payment;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Generates the yearly-sequenced public payment and receipt numbers.
 * Must be called inside a database transaction.
 */
class PaymentNumberGenerator
{
    public function nextPaymentNumber(): string
    {
        return $this->nextNumber('PAY', 'payment_number', 'payment-number');
    }

    public function nextReceiptNumber(): string
    {
        return $this->nextNumber('RCPT', 'receipt_number', 'payment-receipt-number');
    }

    private function nextNumber(string $prefix, string $column, string $lockName): string
    {
        $year = now()->format('Y');

        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["{$lockName}-{$year}"]);
        }

        $latestNumber = Payment::withTrashed()
            ->where($column, 'like', "{$prefix}-{$year}-%")
            ->orderByDesc($column)
            ->lockForUpdate()
            ->value($column);

        $nextSequence = $latestNumber === null
            ? 1
            : ((int) substr($latestNumber, -6)) + 1;

        if ($nextSequence > 999999) {
            throw new DomainException("The {$prefix} number range for this year is exhausted.");
        }

        return sprintf('%s-%s-%06d', $prefix, $year, $nextSequence);
    }
}
