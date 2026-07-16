<?php

namespace App\Models;

use Database\Factories\NotificationTemplateTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'notification_template_id',
    'language',
    'subject',
    'title',
    'message',
])]
class NotificationTemplateTranslation extends Model
{
    /** @use HasFactory<NotificationTemplateTranslationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<NotificationTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }
}
