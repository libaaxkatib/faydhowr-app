<?php

namespace App\Models;

use Database\Factories\SettingsAuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'category',
    'key',
    'old_value',
    'new_value',
    'changed_by',
    'changed_at',
    'ip_address',
])]
class SettingsAuditLog extends Model
{
    /** @use HasFactory<SettingsAuditLogFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'changed_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'old_value' => 'json',
            'new_value' => 'json',
            'changed_at' => 'datetime',
        ];
    }
}
