<?php

namespace App\Actions\Notification;

use App\Jobs\Notification\ProcessNotificationJob;
use App\Models\Notification;
use App\Services\Notification\NotificationChannelManager;

class DispatchNotificationJobAction
{
    public function __construct(private NotificationChannelManager $channels) {}

    public function handle(Notification $notification): void
    {
        ProcessNotificationJob::dispatch($notification->id)
            ->onQueue($this->channels->queueFor($notification->channel));
    }
}
