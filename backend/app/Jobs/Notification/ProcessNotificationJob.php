<?php

namespace App\Jobs\Notification;

use App\Actions\Notification\ProcessNotificationAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessNotificationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $notificationId) {}

    public function handle(ProcessNotificationAction $processNotification): void
    {
        $processNotification->handle($this->notificationId);
    }
}
