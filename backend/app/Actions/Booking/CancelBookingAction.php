<?php

namespace App\Actions\Booking;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\CustomerProfile;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelBookingAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    public function handle(
        CustomerProfile $profile,
        int $bookingId,
        ?string $cancellationReason,
    ): ?Booking {
        $booking = DB::transaction(function () use ($profile, $bookingId, $cancellationReason): ?Booking {
            $profile = CustomerProfile::query()
                ->whereKey($profile)
                ->lockForUpdate()
                ->firstOrFail();

            $booking = $profile
                ->bookings()
                ->whereKey($bookingId)
                ->lockForUpdate()
                ->first();

            if ($booking === null) {
                return null;
            }

            if (! $this->canBeCancelled($booking->status)) {
                throw new DomainException('This booking cannot be cancelled.');
            }

            $booking->status = BookingStatus::Cancelled;
            $booking->cancelled_at = now();
            $booking->cancellation_reason = $cancellationReason;
            $booking->save();

            $booking->statusHistories()->create([
                'status' => BookingStatus::Cancelled,
                'changed_by_type' => 'user',
                'changed_by_id' => $profile->user_id,
                'notes' => $cancellationReason,
            ]);

            return $booking->load(['service', 'serviceMode']);
        });

        if ($booking !== null) {
            $this->dashboardCache->invalidate();
        }

        return $booking;
    }

    private function canBeCancelled(BookingStatus $status): bool
    {
        return ! in_array($status, [
            BookingStatus::Completed,
            BookingStatus::Cancelled,
        ], true);
    }
}
