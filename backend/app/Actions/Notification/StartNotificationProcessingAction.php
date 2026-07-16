<?php

namespace App\Actions\Notification;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use DomainException;

class StartNotificationProcessingAction
{
    public function handle(Notification $notification): Notification
    {
        if ($notification->status !== NotificationStatus::Pending) {
            throw new DomainException('INVALID_NOTIFICATION_STATUS_TRANSITION');
        }

        $attributes = [
            'status' => NotificationStatus::Processing,
        ];

        if ($notification->processing_started_at === null) {
            $attributes['processing_started_at'] = now();
        }

        $notification->forceFill($attributes)->save();

        return $notification->refresh();
    }
}
