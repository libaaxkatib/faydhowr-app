<?php

namespace App\Models;

use App\Enums\Marketing\MarketingRecordStatus;
use App\Enums\Marketing\MarketingRecordType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'record_number',
    'type',
    'assigned_team_id',
    'assigned_admin_id',
    'status',
    'description',
    'feedback',
    'brought_by_admin_id',
    'created_by',
])]
class MarketingRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => MarketingRecordType::class,
            'status' => MarketingRecordStatus::class,
        ];
    }

    public function xarunDetail(): HasOne
    {
        return $this->hasOne(XarunDetail::class);
    }

    public function projectDetail(): HasOne
    {
        return $this->hasOne(ProjectDetail::class);
    }

    public function assignedTeam(): BelongsTo
    {
        return $this->belongsTo(MarketingTeam::class, 'assigned_team_id');
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }

    public function broughtByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'brought_by_admin_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class)->orderBy('follow_up_date');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(MarketingQuotation::class)->latest('created_at');
    }

    public function commissionRecords(): HasMany
    {
        return $this->hasMany(CommissionRecord::class);
    }
}
