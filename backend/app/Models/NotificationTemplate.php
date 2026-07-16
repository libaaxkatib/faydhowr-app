<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationTemplateStatus;
use App\Enums\NotificationType;
use Database\Factories\NotificationTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'template_key',
    'name',
    'type',
    'channel',
    'language',
    'subject',
    'title',
    'message',
    'status',
    'variables',
])]
class NotificationTemplate extends Model
{
    /** @use HasFactory<NotificationTemplateFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'language' => 'en',
        'status' => 'active',
    ];

    /**
     * @return HasMany<NotificationTemplateTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(NotificationTemplateTranslation::class);
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'channel' => NotificationChannel::class,
            'status' => NotificationTemplateStatus::class,
            'variables' => 'array',
        ];
    }
}
