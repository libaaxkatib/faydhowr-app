<?php

namespace App\Actions\Booking;

use App\Actions\Payment\FailActiveBookingPaymentsAction;
use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Events\Audit\AuditEvent;
use App\Events\Booking\BookingCancelled;
use App\Models\Admin;
use App\Models\Booking;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Admin cancellation of a booking (Sprint 27, API Design §18.9.2): allowed
 * from any state before Completed; paid payments remain paid (refunds are
 * V2) and active payments fail automatically inside the same transaction.
 */
class AdminCancelBookingAction
{
    public function __construct(
        private FailActiveBookingPaymentsAction $failActiveBookingPayments,
        private DashboardCacheInvalidatorInterface $dashboardCache,
    ) {}

    public function handle(Admin $admin, int $bookingId, string $reason): Booking
    {
        $previousStatus = null;

        $booking = DB::transaction(function () use ($admin, $bookingId, $reason, &$previousStatus): Booking {
            $booking = Booking::query()
                ->whereKey($bookingId)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($booking->status, [
                BookingStatus::Completed,
                BookingStatus::Closed,
                BookingStatus::Cancelled,
            ], true)) {
                throw new DomainException('This booking cannot be cancelled.');
            }

            $previousStatus = $booking->status;

            $booking->update([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $booking->statusHistories()->create([
                'status' => BookingStatus::Cancelled,
                'changed_by_type' => 'admin',
                'changed_by_id' => $admin->id,
                'notes' => $reason,
            ]);

            $this->failActiveBookingPayments->handle($booking, 'admin', $admin->id);

            DB::afterCommit(fn (): mixed => BookingCancelled::dispatch($booking));

            return $booking;
        });

        event(AuditEvent::record(
            action: AuditAction::BookingCancel,
            admin: $admin,
            description: 'Booking cancelled.',
            entityType: Booking::class,
            entityId: $booking->id,
            metadata: [
                'previous_status' => $previousStatus?->value,
                'new_status' => BookingStatus::Cancelled->value,
                'reason' => $reason,
            ],
        ));

        $this->dashboardCache->invalidate();

        return $booking;
    }
}
