<?php

namespace App\Actions\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Services\Notification\NotificationChannelManager;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessNotificationAction
{
    public function __construct(
        private NotificationChannelManager $channels,
        private StartNotificationProcessingAction $startNotificationProcessing,
        private MarkNotificationSentAction $markNotificationSent,
        private MarkNotificationDeliveredAction $markNotificationDelivered,
        private MarkNotificationFailedAction $markNotificationFailed,
    ) {}

    public function handle(int $notificationId): ?Notification
    {
        return DB::transaction(function () use ($notificationId): ?Notification {
            $notification = Notification::query()
                ->whereKey($notificationId)
                ->lockForUpdate()
                ->first();

            if ($notification === null) {
                return null;
            }

            if ($notification->status !== NotificationStatus::Pending) {
                return $notification;
            }

            $notification = $this->startNotificationProcessing->handle($notification);

            try {
                $this->channels->driver($notification->channel)->send($notification);

                $notification = $this->markNotificationSent->handle($notification);

                // V1 in-app: delivery is persistence completion; email/SMS await future provider callbacks.
                if ($notification->channel === NotificationChannel::InApp) {
                    $notification = $this->markNotificationDelivered->handle($notification);
                }

                return $notification;
            } catch (Throwable $exception) {
                report($exception);

                return $this->markNotificationFailed->handle($notification->refresh());
            }
        });
    }
}
