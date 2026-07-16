<?php

namespace App\Models;

use App\Enums\BookingMediaType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'booking_id',
    'media_type',
    'disk',
    'path',
    'original_name',
    'mime_type',
    'file_size',
    'sort_order',
    'uploaded_at',
])]
class BookingMedia extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'media_type' => BookingMediaType::class,
            'file_size' => 'integer',
            'sort_order' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }
}
