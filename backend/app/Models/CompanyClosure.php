<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One closing of a company / facility with its effective date and reason, and (if it was closed by mistake) its reopening. The closure keeps the exact
 * list of assignments it ended or cancelled (company_closure_assignments), so reopening restores only those.
 */
#[Fillable([
    'client_company_id', 'effective_date', 'reason', 'status', 'closed_by', 'closed_by_role', 'previous_company_status', 'summary',
    'reopened_at', 'reopened_by', 'reopen_reason', 'reopen_summary',
])]
class CompanyClosure extends Model
{
    public const STATUS_CLOSED = 'closed';

    public const STATUS_REOPENED = 'reopened';

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'reopened_at' => 'datetime', 'summary' => 'array', 'reopen_summary' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CompanyClosureAssignment::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'closed_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reopened_by');
    }
}
