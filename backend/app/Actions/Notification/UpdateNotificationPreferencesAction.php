<?php

namespace App\Actions\Notification;

use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\NotificationPreference;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class UpdateNotificationPreferencesAction
{
    public function __construct(private ListNotificationPreferencesAction $listNotificationPreferences) {}

    /**
     * @param  list<array{notification_type: string, in_app: bool, email: bool, sms: bool}>  $preferences
     * @return list<array{notification_type: string, in_app: bool, email: bool, sms: bool}>
     */
    public function handle(Model $recipient, array $preferences): array
    {
        if (! $recipient instanceof Admin && ! $recipient instanceof CustomerProfile) {
            throw new DomainException('UNSUPPORTED_NOTIFICATION_RECIPIENT');
        }

        DB::transaction(function () use ($recipient, $preferences): void {
            NotificationPreference::query()
                ->where('recipient_type', $recipient::class)
                ->where('recipient_id', $recipient->getKey())
                ->delete();

            if ($preferences === []) {
                return;
            }

            $now = now();

            NotificationPreference::query()->insert(
                collect($preferences)
                    ->map(fn (array $preference): array => [
                        'recipient_type' => $recipient::class,
                        'recipient_id' => $recipient->getKey(),
                        'notification_type' => $preference['notification_type'],
                        'in_app' => (bool) $preference['in_app'],
                        'email' => (bool) $preference['email'],
                        'sms' => (bool) $preference['sms'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->all(),
            );
        });

        return $this->listNotificationPreferences->handle($recipient);
    }
}
