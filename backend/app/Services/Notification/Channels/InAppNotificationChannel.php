<?php

namespace App\Services\Notification\Channels;

use App\Contracts\Notification\NotificationChannelInterface;
use App\Models\Notification;

class InAppNotificationChannel implements NotificationChannelInterface
{
    public function send(Notification $notification): void
    {
        // V1: in-app delivery is persistence-only; no external provider.
    }
}
