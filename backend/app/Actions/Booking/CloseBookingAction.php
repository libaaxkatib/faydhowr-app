<?php

namespace App\Actions\Booking;

use App\Contracts\Booking\Services\BookingPaymentGateInterface;
use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\BookingStatus;
use App\Models\Admin;
use App\Models\Booking;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Closes a booking: allowed only when the service is completed and every
 * payment required by the accepted quotation's snapshotted payment policy is
 * confirmed (Sprint 26).
 */
class CloseBookingAction
{
    public function __construct(
        private BookingPaymentGateInterface $paymentGate,
        private DashboardCacheInvalidatorInterface $dashboardCache,
    ) {}

    public function handle(Admin $admin, int $bookingId): Booking
    {
        $booking = DB::transaction(function () use ($admin, $bookingId): Booking {
            $booking = Booking::query()
                ->whereKey($bookingId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($booking->status !== BookingStatus::Completed) {
                throw new DomainException('Only completed bookings can be closed.');
            }

            if (! $this->paymentGate->areAllRequiredPaymentsConfirmed($booking)) {
                throw new DomainException('All required payments must be confirmed before the booking can be closed.');
            }

            $booking->update([
                'status' => BookingStatus::Closed,
            ]);

            $booking->statusHistories()->create([
                'status' => BookingStatus::Closed,
                'changed_by_type' => 'admin',
                'changed_by_id' => $admin->id,
                'notes' => null,
            ]);

            return $booking;
        });

        $this->dashboardCache->invalidate();

        return $booking;
    }
}
