<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One recorded Deep Cleaning completion of a company/guri, entered inside a facility inspection. */
#[Fillable(['facility_inspection_id', 'client_company_id', 'completed_on', 'notes', 'recorded_by', 'changed_by', 'change_reason', 'voided_at', 'voided_by', 'void_reason'])]
class FacilityDeepCleaning extends Model
{
    protected function casts(): array
    {
        return ['completed_on' => 'date', 'voided_at' => 'datetime'];
    }

    /** Completions that count (a withdrawn one stays in the history but is part of no status or report). */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(FacilityInspection::class, 'facility_inspection_id');
    }

    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'recorded_by');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'changed_by');
    }
}
