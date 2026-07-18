<?php

namespace App\Listeners\Notification;

use App\Events\Booking\BookingCompleted;
use App\Events\Notification\NotificationRequested;
use Throwable;

/**
 * Mandatory V1 transactional notification (Sprint 27, API Design §13.7).
 * Dispatched after commit; a notification failure is reported but never
 * surfaces to the caller.
 */
class SendBookingCompletedNotification
{
    public function handle(BookingCompleted $event): void
    {
        $recipient = $event->booking->customerProfile;

        if ($recipient === null) {
            return;
        }

        try {
            event(NotificationRequested::make(
                recipient: $recipient,
                templateKey: 'booking_completed',
                variables: [
                    'booking_number' => (string) $event->booking->booking_number,
                ],
                eventId: 'booking-completed-'.$event->booking->id,
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
