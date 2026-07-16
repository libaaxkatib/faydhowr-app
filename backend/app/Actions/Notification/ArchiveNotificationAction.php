<?php

namespace App\Actions\Notification;

use App\Enums\NotificationArchiveStatus;
use App\Models\ArchivedNotification;
use App\Models\Notification;
use DomainException;
use Illuminate\Support\Facades\DB;

class ArchiveNotificationAction
{
    public function handle(Notification $notification): ArchivedNotification
    {
        return DB::transaction(function () use ($notification): ArchivedNotification {
            $locked = Notification::query()
                ->whereKey($notification->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new DomainException('NOTIFICATION_NOT_FOUND');
            }

            $archiveStatus = NotificationArchiveStatus::tryFromNotificationStatus($locked->status);

            if ($archiveStatus === null) {
                throw new DomainException('NOTIFICATION_NOT_ARCHIVABLE');
            }

            $archived = ArchivedNotification::query()->create([
                'original_notification_id' => $locked->id,
                'recipient_type' => $locked->recipient_type,
                'recipient_id' => $locked->recipient_id,
                'type' => $locked->type,
                'channel' => $locked->channel,
                'status' => $archiveStatus,
                'title' => $locked->title,
                'message' => $locked->message,
                'data' => $locked->data,
                'processing_started_at' => $locked->processing_started_at,
                'sent_at' => $locked->sent_at,
                'delivered_at' => $locked->delivered_at,
                'read_at' => $locked->read_at,
                'failed_at' => $locked->failed_at,
                'archived_at' => now(),
                'created_at' => $locked->created_at,
            ]);

            $locked->delete();

            return $archived;
        });
    }
}
