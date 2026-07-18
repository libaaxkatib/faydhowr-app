<?php

namespace App\Listeners\Notification;

use App\Events\Notification\NotificationRequested;
use App\Events\Quotation\QuotationIssued;
use Throwable;

/**
 * Mandatory V1 transactional notification (Sprint 28, API Design §13.7).
 * Dispatched after commit; a notification failure is reported but never
 * surfaces to the caller.
 */
class SendQuotationIssuedNotification
{
    public function handle(QuotationIssued $event): void
    {
        $recipient = $event->quotation->customerProfile;

        if ($recipient === null) {
            return;
        }

        try {
            event(NotificationRequested::make(
                recipient: $recipient,
                templateKey: 'quotation_issued',
                variables: [
                    'quotation_number' => (string) $event->quotation->quotation_number,
                    'version_number' => (string) $event->revision->version_number,
                ],
                eventId: 'quotation-issued-'.$event->revision->id,
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
