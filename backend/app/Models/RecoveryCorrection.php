<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One applied Super Admin recovery correction (see the recovery_corrections migration). */
#[Fillable(['uuid', 'idempotency_key', 'workflow', 'month', 'subject_type', 'subject_id', 'actor_id', 'reason', 'plan_hash', 'params', 'changes', 'impact', 'status'])]
class RecoveryCorrection extends Model
{
    protected function casts(): array
    {
        return ['params' => 'array', 'changes' => 'array', 'impact' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'actor_id');
    }
}
