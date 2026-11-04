<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An assignment that a company closure ended (its end date was set / shortened) or cancelled (it had not started), with what it looked like before. */
#[Fillable(['company_closure_id', 'work_assignment_id', 'action', 'previous_status', 'previous_end_date', 'new_end_date', 'restored_at', 'restored_by', 'restore_note'])]
class CompanyClosureAssignment extends Model
{
    public const ENDED = 'ended';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return ['previous_end_date' => 'date', 'new_end_date' => 'date', 'restored_at' => 'datetime'];
    }

    public function closure(): BelongsTo
    {
        return $this->belongsTo(CompanyClosure::class, 'company_closure_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeWorkAssignment::class, 'work_assignment_id');
    }
}
