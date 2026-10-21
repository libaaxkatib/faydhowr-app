<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['client_company_id', 'work_location_id', 'inspector_name', 'inspected_on', 'general_notes', 'next_follow_up_on', 'staff_complaints_reported', 'customer_complaints_reported', 'recorded_by', 'voided_at', 'voided_by', 'void_reason'])]
class FacilityInspection extends Model
{
    protected function casts(): array
    {
        return ['inspected_on' => 'date', 'next_follow_up_on' => 'date', 'staff_complaints_reported' => 'boolean', 'customer_complaints_reported' => 'boolean', 'voided_at' => 'datetime'];
    }

    /** Inspections that count (a voided one stays in the history but is not part of any status or report). */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'recorded_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FacilityInspectionItem::class);
    }

    public function uniformIssues(): HasMany
    {
        return $this->hasMany(FacilityUniformIssue::class);
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(FacilityComplaint::class);
    }
}
