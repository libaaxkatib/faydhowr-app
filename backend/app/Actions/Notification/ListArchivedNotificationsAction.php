<?php

namespace App\Actions\Notification;

use App\Enums\NotificationArchiveStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\ArchivedNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListArchivedNotificationsAction
{
    /**
     * @return LengthAwarePaginator<int, ArchivedNotification>
     */
    public function handle(
        ?string $recipientType,
        ?NotificationType $type,
        ?NotificationChannel $channel,
        ?NotificationArchiveStatus $status,
        ?string $archivedFrom,
        ?string $archivedTo,
        int $perPage,
    ): LengthAwarePaginator {
        return ArchivedNotification::query()
            ->when(
                $recipientType !== null,
                fn ($query) => $query->where('recipient_type', $recipientType),
            )
            ->when(
                $type !== null,
                fn ($query) => $query->where('type', $type->value),
            )
            ->when(
                $channel !== null,
                fn ($query) => $query->where('channel', $channel->value),
            )
            ->when(
                $status !== null,
                fn ($query) => $query->where('status', $status->value),
            )
            ->when(
                $archivedFrom !== null,
                fn ($query) => $query->whereDate('archived_at', '>=', $archivedFrom),
            )
            ->when(
                $archivedTo !== null,
                fn ($query) => $query->whereDate('archived_at', '<=', $archivedTo),
            )
            ->latest('archived_at')
            ->latest('id')
            ->paginate($perPage);
    }
}
