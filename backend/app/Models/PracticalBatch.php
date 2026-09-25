<?php

namespace App\Models;

use App\Enums\PracticalBatchStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'batch_number',
    'batch_date',
    'team_or_group',
    'trainer_admin_id',
    'location',
    'status',
    'notes',
    'created_by',
])]
class PracticalBatch extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'batch_date' => 'date',
            'status' => PracticalBatchStatus::class,
        ];
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'trainer_admin_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(EmployeePracticalAssessment::class);
    }
}
