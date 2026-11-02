<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry of a Replacement cycle's ledger, always linked to the ORIGINAL assignment (`work_assignment_id`). */
#[Fillable(['absence_id', 'work_assignment_id', 'event_type', 'event_date', 'replacement_assignment_id', 'admin_id', 'details'])]
class AssignmentCycleEvent extends Model
{
    public const ABSENCE_STARTED = 'absence_started';

    public const REPLACEMENT_STARTED = 'replacement_started';

    public const REPLACEMENT_ENDED = 'replacement_ended';

    public const RETURNED = 'returned';

    public const ORIGINAL_TERMINATED = 'original_terminated';

    /** The cover was cancelled as an entry made by mistake: the absence is closed with zero days, as if the holder had never been away. */
    public const COVER_CANCELLED = 'cover_cancelled';

    /** A Super Admin Recovery Center correction moved the last working day and/or the return date (details hold before, after and the reason). */
    public const WINDOW_CORRECTED = 'window_corrected';

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'details' => 'array',
        ];
    }

    public function absence(): BelongsTo
    {
        return $this->belongsTo(AssignmentAbsence::class, 'absence_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
