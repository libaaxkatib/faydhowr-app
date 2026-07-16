<?php

namespace App\Models;

use App\Enums\NotificationArchiveStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use Database\Factories\ArchivedNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'original_notification_id',
    'recipient_type',
    'recipient_id',
    'type',
    'channel',
    'status',
    'title',
    'message',
    'data',
    'processing_started_at',
    'sent_at',
    'delivered_at',
    'read_at',
    'failed_at',
    'archived_at',
    'created_at',
])]
class ArchivedNotification extends Model
{
    /** @use HasFactory<ArchivedNotificationFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return MorphTo<Model, $this>
     */
    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'channel' => NotificationChannel::class,
            'status' => NotificationArchiveStatus::class,
            'data' => 'array',
            'processing_started_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'archived_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
