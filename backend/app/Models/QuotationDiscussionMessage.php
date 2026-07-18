<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'quotation_id',
    'sender_type',
    'sender_id',
    'message',
])]
class QuotationDiscussionMessage extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * @return HasMany<QuotationMessageAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(QuotationMessageAttachment::class, 'quotation_discussion_message_id');
    }
}
