<?php

namespace App\Actions\Notification;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class MarkNotificationReadAction
{
    public function handle(Model $recipient, int $notificationId): Notification
    {
        return DB::transaction(function () use ($recipient, $notificationId): Notification {
            $notification = Notification::query()
                ->whereKey($notificationId)
                ->where('recipient_type', $recipient::class)
                ->where('recipient_id', $recipient->getKey())
                ->lockForUpdate()
                ->first();

            if ($notification === null) {
                throw new DomainException('NOTIFICATION_NOT_FOUND');
            }

            if ($notification->status === NotificationStatus::Read) {
                return $notification;
            }

            if ($notification->status !== NotificationStatus::Delivered) {
                throw new DomainException('INVALID_NOTIFICATION_STATUS_TRANSITION');
            }

            $attributes = [
                'status' => NotificationStatus::Read,
            ];

            if ($notification->read_at === null) {
                $attributes['read_at'] = now();
            }

            $notification->forceFill($attributes)->save();

            return $notification->refresh();
        });
    }
}
