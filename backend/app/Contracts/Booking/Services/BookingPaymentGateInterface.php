<?php

namespace App\Contracts\Booking\Services;

use App\Models\Booking;

interface BookingPaymentGateInterface
{
    /**
     * Whether the payment required by the accepted quotation's snapshotted
     * payment policy has been confirmed, allowing the booking to be Scheduled.
     */
    public function isSchedulingPaymentConfirmed(Booking $booking): bool;

    /**
     * Whether every required payment stage for the accepted quotation's
     * snapshotted payment policy is confirmed, allowing the booking to close.
     */
    public function areAllRequiredPaymentsConfirmed(Booking $booking): bool;
}
