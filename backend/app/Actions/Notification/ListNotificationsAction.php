<?php

namespace App\Actions\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class ListNotificationsAction
{
    /**
     * @return LengthAwarePaginator<int, Notification>
     */
    public function handle(
        Model $recipient,
        ?NotificationStatus $status,
        ?NotificationType $type,
        ?NotificationChannel $channel,
        int $perPage,
    ): LengthAwarePaginator {
        return Notification::query()
            ->where('recipient_type', $recipient::class)
            ->where('recipient_id', $recipient->getKey())
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->when($type !== null, fn ($query) => $query->where('type', $type->value))
            ->when($channel !== null, fn ($query) => $query->where('channel', $channel->value))
            ->latest()
            ->paginate($perPage);
    }
}
