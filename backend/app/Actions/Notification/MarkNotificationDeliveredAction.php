<?php

namespace App\Actions\Notification;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use DomainException;

class MarkNotificationDeliveredAction
{
    public function handle(Notification $notification): Notification
    {
        if ($notification->status !== NotificationStatus::Sent) {
            throw new DomainException('INVALID_NOTIFICATION_STATUS_TRANSITION');
        }

        $attributes = [
            'status' => NotificationStatus::Delivered,
        ];

        if ($notification->delivered_at === null) {
            $attributes['delivered_at'] = now();
        }

        $notification->forceFill($attributes)->save();

        return $notification->refresh();
    }
}
