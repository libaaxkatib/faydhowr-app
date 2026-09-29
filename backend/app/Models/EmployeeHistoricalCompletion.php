<?php

namespace App\Models;

use App\Enums\EmployeeHistoricalCompletionStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #12: historical evidence/context only, never live operational
 * workflow state — see the migration for the full business-rule writeup.
 * Never read by pipeline/queue logic; display and reporting only.
 */
#[Fillable(['employee_id', 'stage', 'source', 'source_reference', 'source_notes', 'completed_at'])]
class EmployeeHistoricalCompletion extends Model
{
    protected function casts(): array
    {
        return [
            'stage' => EmployeeHistoricalCompletionStage::class,
            'completed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
