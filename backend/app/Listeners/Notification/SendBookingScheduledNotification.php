<?php

namespace App\Listeners\Notification;

use App\Events\Booking\BookingScheduled;
use App\Events\Notification\NotificationRequested;
use Throwable;

/**
 * Mandatory V1 transactional notification (Sprint 27, API Design §13.7).
 * Dispatched after commit; a notification failure is reported but never
 * surfaces to the caller.
 */
class SendBookingScheduledNotification
{
    public function handle(BookingScheduled $event): void
    {
        $recipient = $event->booking->customerProfile;

        if ($recipient === null) {
            return;
        }

        try {
            event(NotificationRequested::make(
                recipient: $recipient,
                templateKey: 'booking_scheduled',
                variables: [
                    'booking_number' => (string) $event->booking->booking_number,
                    'scheduled_start_at' => (string) $event->booking->scheduled_start_at?->toDayDateTimeString(),
                ],
                eventId: 'booking-scheduled-'.$event->booking->id,
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
