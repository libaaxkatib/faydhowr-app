<?php

namespace App\Listeners\Notification;

use App\Events\Notification\NotificationRequested;
use App\Events\Quotation\QuotationDiscussionReplyCreated;
use Throwable;

/**
 * Mandatory V1 transactional notification (Sprint 28, API Design §13.7).
 * The counterparty is notified: an admin reply notifies the customer; a
 * customer reply notifies the assigned reviewer (when one exists).
 */
class SendQuotationDiscussionReplyNotification
{
    public function handle(QuotationDiscussionReplyCreated $event): void
    {
        $recipient = $event->message->sender_type === 'admin'
            ? $event->quotation->customerProfile
            : $event->quotation->assignedAdmin;

        if ($recipient === null) {
            return;
        }

        try {
            event(NotificationRequested::make(
                recipient: $recipient,
                templateKey: 'quotation_discussion_reply',
                variables: [
                    'quotation_number' => (string) $event->quotation->quotation_number,
                ],
                eventId: 'quotation-discussion-reply-'.$event->message->id,
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
