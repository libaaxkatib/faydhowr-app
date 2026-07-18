<?php

namespace App\Actions\Booking;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Events\Audit\AuditEvent;
use App\Events\Booking\BookingCompleted;
use App\Models\Admin;
use App\Models\Booking;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Marks an in-progress booking as Completed (Sprint 27, API Design §18.9.2).
 * Closing remains a separate step gated by the final payment confirmation.
 */
class CompleteBookingAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    public function handle(Admin $admin, int $bookingId): Booking
    {
        $booking = DB::transaction(function () use ($admin, $bookingId): Booking {
            $booking = Booking::query()
                ->whereKey($bookingId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($booking->status !== BookingStatus::InProgress) {
                throw new DomainException('Only in-progress bookings can be completed.');
            }

            $booking->update([
                'status' => BookingStatus::Completed,
            ]);

            $booking->statusHistories()->create([
                'status' => BookingStatus::Completed,
                'changed_by_type' => 'admin',
                'changed_by_id' => $admin->id,
                'notes' => null,
            ]);

            DB::afterCommit(fn (): mixed => BookingCompleted::dispatch($booking));

            return $booking;
        });

        event(AuditEvent::record(
            action: AuditAction::BookingComplete,
            admin: $admin,
            description: 'Booking completed.',
            entityType: Booking::class,
            entityId: $booking->id,
            metadata: [
                'previous_status' => BookingStatus::InProgress->value,
                'new_status' => BookingStatus::Completed->value,
            ],
        ));

        $this->dashboardCache->invalidate();

        return $booking;
    }
}
