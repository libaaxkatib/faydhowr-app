<?php

namespace App\Models;

use App\Enums\Marketing\FollowUpStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['marketing_record_id', 'follow_up_date', 'status', 'assigned_admin_id', 'created_by'])]
class FollowUp extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'follow_up_date' => 'date',
            'status' => FollowUpStatus::class,
        ];
    }

    public function marketingRecord(): BelongsTo
    {
        return $this->belongsTo(MarketingRecord::class);
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(FollowUpHistory::class)->latest('created_at');
    }
}
