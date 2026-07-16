<?php

namespace App\Actions\Notification;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use DomainException;

class MarkNotificationSentAction
{
    public function handle(Notification $notification): Notification
    {
        if ($notification->status !== NotificationStatus::Processing) {
            throw new DomainException('INVALID_NOTIFICATION_STATUS_TRANSITION');
        }

        $attributes = [
            'status' => NotificationStatus::Sent,
        ];

        if ($notification->sent_at === null) {
            $attributes['sent_at'] = now();
        }

        $notification->forceFill($attributes)->save();

        return $notification->refresh();
    }
}
