<?php

namespace App\Models;

use App\Enums\FacilityComplaintStatus;
use App\Enums\FacilityComplaintType;
use App\Enums\FacilitySatisfaction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'client_company_id', 'facility_inspection_id', 'type', 'details', 'reporter_name', 'reporter_type', 'reporter_phone', 'reporter_employee_id',
    'responsible_person', 'response', 'corrective_action', 'follow_up_on', 'reported_on', 'responded_on', 'resolved_on', 'status',
    'satisfaction', 'satisfaction_evidence', 'satisfaction_recorded_on', 'recorded_by', 'voided_at', 'voided_by', 'void_reason',
])]
class FacilityComplaint extends Model
{
    protected function casts(): array
    {
        return [
            'type' => FacilityComplaintType::class,
            'status' => FacilityComplaintStatus::class,
            'satisfaction' => FacilitySatisfaction::class,
            'reported_on' => 'date',
            'responded_on' => 'date',
            'resolved_on' => 'date',
            'follow_up_on' => 'date',
            'satisfaction_recorded_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    /** Complaints that count (a voided one stays in the history but is not part of any total). */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(FacilityInspection::class, 'facility_inspection_id');
    }

    public function reporterEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reporter_employee_id')->withTrashed();
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'recorded_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FacilityComplaintEvent::class)->orderBy('id');
    }
}
