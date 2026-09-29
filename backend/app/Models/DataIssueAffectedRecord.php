<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['data_issue_id', 'recordable_type', 'recordable_id', 'context_note', 'created_at'])]
class DataIssueAffectedRecord extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function dataIssue(): BelongsTo
    {
        return $this->belongsTo(DataIssue::class);
    }

    /**
     * An affected employee may already be soft-deleted (e.g. a duplicate that
     * was later removed per Issue #9) — the whole point of this feature is to
     * keep that record traceable, so trashed employees still resolve here.
     */
    public function recordable(): MorphTo
    {
        return $this->morphTo()->constrain([
            Employee::class => fn (Builder $query) => $query->withTrashed(),
        ]);
    }
}
