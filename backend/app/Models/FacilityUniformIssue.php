<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['facility_inspection_id', 'employee_id', 'issue', 'warning_given', 'reason_not_worn', 'corrective_action', 'follow_up_on'])]
class FacilityUniformIssue extends Model
{
    protected function casts(): array
    {
        return ['warning_given' => 'boolean', 'follow_up_on' => 'date'];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(FacilityInspection::class, 'facility_inspection_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }
}
