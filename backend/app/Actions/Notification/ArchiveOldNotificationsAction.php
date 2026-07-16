<?php

namespace App\Actions\Notification;

use App\Enums\NotificationArchiveStatus;
use App\Models\Notification;
use DateTimeInterface;

class ArchiveOldNotificationsAction
{
    public function __construct(private ArchiveNotificationAction $archiveNotification) {}

    public function handle(?DateTimeInterface $olderThan = null, ?int $limit = null): int
    {
        $query = Notification::query()
            ->whereIn('status', NotificationArchiveStatus::values())
            ->when(
                $olderThan !== null,
                fn ($builder) => $builder->where('created_at', '<', $olderThan),
            )
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $ids = $query->pluck('id');
        $archivedCount = 0;

        foreach ($ids as $id) {
            $notification = Notification::query()->find($id);

            if ($notification === null) {
                continue;
            }

            $this->archiveNotification->handle($notification);
            $archivedCount++;
        }

        return $archivedCount;
    }
}
