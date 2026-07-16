<?php

namespace App\Actions\Notification;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Model;

class GetUnreadNotificationCountAction
{
    public function handle(Model $recipient): int
    {
        return Notification::query()
            ->where('recipient_type', $recipient::class)
            ->where('recipient_id', $recipient->getKey())
            ->where('status', '!=', NotificationStatus::Read->value)
            ->count();
    }
}
