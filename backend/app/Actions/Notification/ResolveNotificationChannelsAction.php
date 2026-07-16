<?php

namespace App\Actions\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\NotificationPreference;
use DomainException;
use Illuminate\Database\Eloquent\Model;

class ResolveNotificationChannelsAction
{
    /**
     * @return list<NotificationChannel>
     */
    public function handle(Model $recipient, NotificationType $type): array
    {
        if (! $recipient instanceof Admin && ! $recipient instanceof CustomerProfile) {
            throw new DomainException('UNSUPPORTED_NOTIFICATION_RECIPIENT');
        }

        $preference = NotificationPreference::query()
            ->where('recipient_type', $recipient::class)
            ->where('recipient_id', $recipient->getKey())
            ->where('notification_type', $type->value)
            ->first();

        $channels = [];

        if ($preference?->in_app ?? true) {
            $channels[] = NotificationChannel::InApp;
        }

        if ($preference?->email ?? true) {
            $channels[] = NotificationChannel::Email;
        }

        if ($preference?->sms ?? false) {
            $channels[] = NotificationChannel::Sms;
        }

        return $channels;
    }
}
