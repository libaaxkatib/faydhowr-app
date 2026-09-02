<?php

namespace App\Models;

use App\Enums\Marketing\FollowUpAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['follow_up_id', 'action', 'note', 'performed_by'])]
class FollowUpHistory extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['action' => FollowUpAction::class];
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(FollowUp::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'performed_by');
    }
}
