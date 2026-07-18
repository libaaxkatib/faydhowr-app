<?php

namespace App\Models;

use App\Enums\Home\HeroBannerActionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'title',
    'subtitle',
    'image_url',
    'action_type',
    'action_reference',
    'sort_order',
    'is_active',
    'starts_at',
    'ends_at',
])]
class HeroBanner extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'action_type' => HeroBannerActionType::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
