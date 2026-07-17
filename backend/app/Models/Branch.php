<?php

namespace App\Models;

use App\Enums\Settings\BranchStatus;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'code',
    'name',
    'city',
    'status',
    'is_default',
    'activated_at',
    'activated_by',
])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'activated_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', BranchStatus::Active);
    }

    public function isActive(): bool
    {
        return $this->status === BranchStatus::Active;
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => BranchStatus::class,
            'is_default' => 'boolean',
            'activated_at' => 'datetime',
        ];
    }
}
