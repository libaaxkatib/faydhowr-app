<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'quotation_discussion_message_id',
    'upload_id',
])]
class QuotationMessageAttachment extends Model
{
    public const ?string UPDATED_AT = null;

    /**
     * @return BelongsTo<QuotationDiscussionMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(QuotationDiscussionMessage::class, 'quotation_discussion_message_id');
    }

    /**
     * @return BelongsTo<Upload, $this>
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }
}
