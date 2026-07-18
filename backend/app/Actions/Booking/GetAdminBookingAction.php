<?php

namespace App\Actions\Booking;

use App\Contracts\Booking\Services\BookingPaymentGateInterface;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Collection;

/**
 * Loads the full admin booking detail (Sprint 27, API Design §18.9.2):
 * booking, quotations, linked payments, and the computed payment gate flags
 * (`can_schedule`, `can_close`).
 */
class GetAdminBookingAction
{
    public function __construct(private BookingPaymentGateInterface $paymentGate) {}

    /**
     * @return array{booking: Booking, payments: Collection<int, Payment>, can_schedule: bool, can_close: bool}|null
     */
    public function handle(int $bookingId): ?array
    {
        $booking = Booking::query()
            ->with(['customerProfile', 'service', 'serviceMode', 'statusHistories', 'quotations'])
            ->whereKey($bookingId)
            ->first();

        if ($booking === null) {
            return null;
        }

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
            ->latest('id')
            ->get();

        return [
            'booking' => $booking,
            'payments' => $payments,
            'can_schedule' => $booking->status === BookingStatus::Accepted
                && $this->paymentGate->isSchedulingPaymentConfirmed($booking),
            'can_close' => $booking->status === BookingStatus::Completed
                && $this->paymentGate->areAllRequiredPaymentsConfirmed($booking),
        ];
    }
}
