<?php

namespace App\Models;

use App\Enums\TrainingParticipantResult;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['training_batch_id', 'employee_id', 'result', 'notes'])]
class TrainingBatchParticipant extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'result' => TrainingParticipantResult::class,
        ];
    }

    public function trainingBatch(): BelongsTo
    {
        return $this->belongsTo(TrainingBatch::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
