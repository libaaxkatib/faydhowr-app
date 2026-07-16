<?php

namespace App\Actions\Notification;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class MarkAllNotificationsReadAction
{
    public function handle(Model $recipient): int
    {
        return DB::transaction(function () use ($recipient): int {
            $now = now();

            return Notification::query()
                ->where('recipient_type', $recipient::class)
                ->where('recipient_id', $recipient->getKey())
                ->where('status', NotificationStatus::Delivered->value)
                ->whereNull('read_at')
                ->update([
                    'status' => NotificationStatus::Read->value,
                    'read_at' => $now,
                    'updated_at' => $now,
                ]);
        });
    }
}
