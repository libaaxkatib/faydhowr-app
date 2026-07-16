<?php

namespace App\Actions\Notification;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use DomainException;

class MarkNotificationFailedAction
{
    public function handle(Notification $notification): Notification
    {
        if ($notification->status !== NotificationStatus::Processing) {
            throw new DomainException('INVALID_NOTIFICATION_STATUS_TRANSITION');
        }

        $attributes = [
            'status' => NotificationStatus::Failed,
        ];

        if ($notification->failed_at === null) {
            $attributes['failed_at'] = now();
        }

        $notification->forceFill($attributes)->save();

        return $notification->refresh();
    }
}
