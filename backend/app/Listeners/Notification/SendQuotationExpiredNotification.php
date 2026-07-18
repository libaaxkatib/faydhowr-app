<?php

namespace App\Listeners\Notification;

use App\Events\Notification\NotificationRequested;
use App\Events\Quotation\QuotationExpired;
use Throwable;

/**
 * Mandatory V1 transactional notification (Sprint 28, API Design §13.7).
 * Dispatched after commit; a notification failure is reported but never
 * surfaces to the caller.
 */
class SendQuotationExpiredNotification
{
    public function handle(QuotationExpired $event): void
    {
        $recipient = $event->quotation->customerProfile;

        if ($recipient === null) {
            return;
        }

        try {
            event(NotificationRequested::make(
                recipient: $recipient,
                templateKey: 'quotation_expired',
                variables: [
                    'quotation_number' => (string) $event->quotation->quotation_number,
                ],
                eventId: 'quotation-expired-'.$event->quotation->id,
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
