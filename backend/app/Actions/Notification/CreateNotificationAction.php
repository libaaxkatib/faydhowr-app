<?php

namespace App\Actions\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\Notification;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CreateNotificationAction
{
    /**
     * @param  list<NotificationChannel>  $channels
     * @param  array<string, mixed>|null  $data
     * @return Collection<int, Notification>
     */
    public function handle(
        Model $recipient,
        NotificationType $type,
        array $channels,
        string $title,
        string $message,
        string $eventId,
        ?array $data = null,
    ): Collection {
        if (! $recipient instanceof Admin && ! $recipient instanceof CustomerProfile) {
            throw new DomainException('UNSUPPORTED_NOTIFICATION_RECIPIENT');
        }

        if ($channels === []) {
            throw new DomainException('NOTIFICATION_CHANNELS_REQUIRED');
        }

        return DB::transaction(function () use ($recipient, $type, $channels, $title, $message, $eventId, $data): Collection {
            $created = new Collection;

            foreach ($channels as $channel) {
                if ($this->alreadyPersisted($recipient, $channel, $eventId)) {
                    continue;
                }

                $payload = $data ?? [];
                $payload['event_id'] = $eventId;

                try {
                    $created->push(Notification::query()->create([
                        'recipient_type' => $recipient::class,
                        'recipient_id' => $recipient->getKey(),
                        'type' => $type,
                        'channel' => $channel,
                        'status' => NotificationStatus::Pending,
                        'title' => $title,
                        'message' => $message,
                        'data' => $payload,
                        'event_id' => $eventId,
                        'read_at' => null,
                        'sent_at' => null,
                    ]));
                } catch (UniqueConstraintViolationException) {
                    continue;
                }
            }

            return $created;
        });
    }

    private function alreadyPersisted(Model $recipient, NotificationChannel $channel, string $eventId): bool
    {
        return Notification::query()
            ->where('recipient_type', $recipient::class)
            ->where('recipient_id', $recipient->getKey())
            ->where('channel', $channel->value)
            ->where('event_id', $eventId)
            ->exists();
    }
}
