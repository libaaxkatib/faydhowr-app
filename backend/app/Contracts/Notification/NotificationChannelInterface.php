<?php

namespace App\Contracts\Notification;

use App\Models\Notification;

interface NotificationChannelInterface
{
    /**
     * Deliver a notification through this channel.
     * V1 implementations persist delivery intent only — no external providers.
     */
    public function send(Notification $notification): void;
}
