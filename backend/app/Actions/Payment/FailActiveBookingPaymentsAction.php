<?php

namespace App\Actions\Payment;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;

/**
 * Booking cancellation payment rule (Sprint 27, API Design §8.4): paid
 * payments remain paid (refunds are V2); every active payment linked to the
 * booking's orders becomes FAILED automatically. Must be called inside the
 * same database transaction as the cancellation.
 */
class FailActiveBookingPaymentsAction
{
    public function handle(Booking $booking, string $changedByType, int $changedById): void
    {
        $payments = Payment::query()
            ->where('payable_type', Order::class)
            ->whereIn(
                'payable_id',
                Order::query()
                    ->whereIn(
                        'quotation_id',
                        Quotation::query()->where('booking_id', $booking->id)->select('id'),
                    )
                    ->select('id'),
            )
            ->active()
            ->lockForUpdate()
            ->get();

        foreach ($payments as $payment) {
            $payment->update([
                'status' => PaymentStatus::Failed,
            ]);

            $payment->statusHistories()->create([
                'status' => PaymentStatus::Failed,
                'changed_by_type' => $changedByType,
                'changed_by_id' => $changedById,
                'notes' => 'Booking cancelled.',
            ]);
        }
    }
}
