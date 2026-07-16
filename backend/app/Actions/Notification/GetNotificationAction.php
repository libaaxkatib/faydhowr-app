<?php

namespace App\Actions\Notification;

use App\Models\Notification;
use Illuminate\Database\Eloquent\Model;

class GetNotificationAction
{
    public function handle(Model $recipient, int $notificationId): ?Notification
    {
        return Notification::query()
            ->whereKey($notificationId)
            ->where('recipient_type', $recipient::class)
            ->where('recipient_id', $recipient->getKey())
            ->first();
    }
}
