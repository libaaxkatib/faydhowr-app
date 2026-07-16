<?php

namespace App\Actions\Notification;

use App\Enums\NotificationType;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\NotificationPreference;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ListNotificationPreferencesAction
{
    /**
     * @return list<array{notification_type: string, in_app: bool, email: bool, sms: bool}>
     */
    public function handle(Model $recipient): array
    {
        if (! $recipient instanceof Admin && ! $recipient instanceof CustomerProfile) {
            throw new DomainException('UNSUPPORTED_NOTIFICATION_RECIPIENT');
        }

        /** @var Collection<string, NotificationPreference> $stored */
        $stored = NotificationPreference::query()
            ->where('recipient_type', $recipient::class)
            ->where('recipient_id', $recipient->getKey())
            ->get()
            ->keyBy(fn (NotificationPreference $preference): string => $preference->notification_type->value);

        $preferences = [];

        foreach (NotificationType::cases() as $type) {
            $preference = $stored->get($type->value);

            $preferences[] = [
                'notification_type' => $type->value,
                'in_app' => $preference?->in_app ?? true,
                'email' => $preference?->email ?? true,
                'sms' => $preference?->sms ?? false,
            ];
        }

        return $preferences;
    }
}
