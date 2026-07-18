<?php

namespace App\Listeners\Notification;

use App\Events\Notification\NotificationRequested;
use App\Events\Payment\PaymentPaid;
use Throwable;

/**
 * Mandatory V1 transactional notification (Sprint 27, API Design §13.7).
 * Dispatched after commit; a notification failure is reported but never
 * surfaces to the caller.
 */
class SendPaymentConfirmedNotification
{
    public function handle(PaymentPaid $event): void
    {
        $recipient = $event->payment->customerProfile;

        if ($recipient === null) {
            return;
        }

        try {
            event(NotificationRequested::make(
                recipient: $recipient,
                templateKey: 'payment_confirmed',
                variables: [
                    'payment_number' => (string) $event->payment->payment_number,
                    'amount' => (string) $event->payment->amount,
                    'currency' => (string) $event->payment->currency,
                ],
                eventId: 'payment-confirmed-'.$event->payment->id,
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
