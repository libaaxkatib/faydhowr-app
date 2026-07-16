<?php

namespace App\Services\Notification\Channels;

use App\Contracts\Notification\NotificationChannelInterface;
use App\Models\Notification;

class EmailNotificationChannel implements NotificationChannelInterface
{
    public function send(Notification $notification): void
    {
        // V1: email delivery is persistence-only; no external provider.
    }
}
