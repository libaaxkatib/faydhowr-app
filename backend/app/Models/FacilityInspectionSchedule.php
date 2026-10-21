<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['client_company_id', 'frequency_days', 'due_soon_days', 'notes', 'set_by'])]
class FacilityInspectionSchedule extends Model
{
    protected function casts(): array
    {
        return ['frequency_days' => 'integer', 'due_soon_days' => 'integer'];
    }

    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }
}
