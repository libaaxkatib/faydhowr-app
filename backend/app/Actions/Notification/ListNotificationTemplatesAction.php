<?php

namespace App\Actions\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationTemplateStatus;
use App\Enums\NotificationType;
use App\Models\NotificationTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListNotificationTemplatesAction
{
    /**
     * @return LengthAwarePaginator<int, NotificationTemplate>
     */
    public function handle(
        ?NotificationTemplateStatus $status,
        ?NotificationType $type,
        ?NotificationChannel $channel,
        int $perPage,
    ): LengthAwarePaginator {
        return NotificationTemplate::query()
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->when($type !== null, fn ($query) => $query->where('type', $type->value))
            ->when($channel !== null, fn ($query) => $query->where('channel', $channel->value))
            ->orderBy('template_key')
            ->paginate($perPage);
    }
}
