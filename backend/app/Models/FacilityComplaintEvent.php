<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['facility_complaint_id', 'admin_id', 'event', 'from_status', 'to_status', 'note'])]
class FacilityComplaintEvent extends Model
{
    public const UPDATED_AT = null;

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(FacilityComplaint::class, 'facility_complaint_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
