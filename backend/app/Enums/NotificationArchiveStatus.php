<?php

namespace App\Enums;

enum NotificationArchiveStatus: string
{
    case Read = 'read';
    case Failed = 'failed';

    public static function tryFromNotificationStatus(NotificationStatus $status): ?self
    {
        return self::tryFrom($status->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
