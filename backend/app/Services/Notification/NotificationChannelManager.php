<?php

namespace App\Services\Notification;

use App\Contracts\Notification\NotificationChannelInterface;
use App\Enums\NotificationChannel;
use InvalidArgumentException;

class NotificationChannelManager
{
    public const QUEUE_IN_APP = 'notifications-in-app';

    public const QUEUE_EMAIL = 'notifications-email';

    public const QUEUE_SMS = 'notifications-sms';

    /**
     * @var array<string, NotificationChannelInterface>
     */
    private array $drivers = [];

    /**
     * @param  iterable<string, NotificationChannelInterface>  $drivers
     */
    public function __construct(iterable $drivers = [])
    {
        foreach ($drivers as $channel => $driver) {
            $this->register($channel, $driver);
        }
    }

    public function register(string $channel, NotificationChannelInterface $driver): void
    {
        $this->drivers[$channel] = $driver;
    }

    public function driver(NotificationChannel|string $channel): NotificationChannelInterface
    {
        $key = $channel instanceof NotificationChannel ? $channel->value : $channel;

        if (! isset($this->drivers[$key])) {
            throw new InvalidArgumentException("Notification channel [{$key}] is not configured.");
        }

        return $this->drivers[$key];
    }

    public function queueFor(NotificationChannel|string $channel): string
    {
        $key = $channel instanceof NotificationChannel ? $channel->value : $channel;

        return match ($key) {
            NotificationChannel::InApp->value => self::QUEUE_IN_APP,
            NotificationChannel::Email->value => self::QUEUE_EMAIL,
            NotificationChannel::Sms->value => self::QUEUE_SMS,
            default => throw new InvalidArgumentException("Notification channel [{$key}] has no queue mapping."),
        };
    }
}
