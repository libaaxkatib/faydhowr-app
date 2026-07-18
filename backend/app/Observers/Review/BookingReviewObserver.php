<?php

namespace App\Observers\Review;

use App\Contracts\Review\Services\ReviewServiceInterface;
use App\Enums\BookingStatus;
use App\Models\Booking;

/**
 * Auto-hides a booking's review when a completed booking is reverted to a
 * non-completed status (SRS BR-R11). Reviews are never deleted automatically.
 */
class BookingReviewObserver
{
    public function __construct(private ReviewServiceInterface $reviews) {}

    public function updated(Booking $booking): void
    {
        if (! $booking->wasChanged('status')) {
            return;
        }

        $original = $booking->getOriginal('status');

        if ($original !== BookingStatus::Completed || $booking->status === BookingStatus::Completed) {
            return;
        }

        // Closed is a forward transition (service completed and all required
        // payments confirmed — Sprint 26), not a reversion.
        if ($booking->status === BookingStatus::Closed) {
            return;
        }

        $this->reviews->hideForBookingReversion($booking);
    }
}
