<?php

namespace App\Actions\Booking;

use App\Contracts\Booking\Services\BookingPaymentGateInterface;
use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\BookingStatus;
use App\Models\Admin;
use App\Models\Booking;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Moves an accepted booking to Scheduled. A booking may be scheduled only
 * after the payment required by the accepted quotation's snapshotted payment
 * policy is confirmed (Sprint 26 payment gates).
 */
class ScheduleBookingAction
{
    public function __construct(
        private BookingPaymentGateInterface $paymentGate,
        private DashboardCacheInvalidatorInterface $dashboardCache,
    ) {}

    public function handle(
        Admin $admin,
        int $bookingId,
        DateTimeInterface $scheduledStartAt,
        DateTimeInterface $scheduledEndAt,
    ): Booking {
        $booking = DB::transaction(function () use ($admin, $bookingId, $scheduledStartAt, $scheduledEndAt): Booking {
            $booking = Booking::query()
                ->whereKey($bookingId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($booking->status !== BookingStatus::Accepted) {
                throw new DomainException('Only accepted bookings can be scheduled.');
            }

            if (! $this->paymentGate->isSchedulingPaymentConfirmed($booking)) {
                throw new DomainException('The required payment must be confirmed before the booking can be scheduled.');
            }

            $booking->update([
                'status' => BookingStatus::Scheduled,
                'scheduled_start_at' => $scheduledStartAt,
                'scheduled_end_at' => $scheduledEndAt,
            ]);

            $booking->statusHistories()->create([
                'status' => BookingStatus::Scheduled,
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
