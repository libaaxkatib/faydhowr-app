<?php

namespace App\Actions\Booking;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Booking;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Moves a scheduled booking to In Progress when the crew starts the service
 * (Sprint 27, API Design §18.9.2).
 */
class StartBookingAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    public function handle(Admin $admin, int $bookingId): Booking
    {
        $booking = DB::transaction(function () use ($admin, $bookingId): Booking {
            $booking = Booking::query()
                ->whereKey($bookingId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($booking->status !== BookingStatus::Scheduled) {
                throw new DomainException('Only scheduled bookings can be started.');
            }

            $booking->update([
                'status' => BookingStatus::InProgress,
            ]);

            $booking->statusHistories()->create([
                'status' => BookingStatus::InProgress,
                'changed_by_type' => 'admin',
                'changed_by_id' => $admin->id,
                'notes' => null,
            ]);

            return $booking;
        });

        event(AuditEvent::record(
            action: AuditAction::BookingStart,
            admin: $admin,
            description: 'Booking started.',
            entityType: Booking::class,
            entityId: $booking->id,
            metadata: [
                'previous_status' => BookingStatus::Scheduled->value,
                'new_status' => BookingStatus::InProgress->value,
            ],
        ));

        $this->dashboardCache->invalidate();

        return $booking;
    }
}
