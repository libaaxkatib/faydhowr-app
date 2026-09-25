<?php

namespace App\Models;

use App\Enums\TemporaryReplacementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'work_assignment_id',
    'replacement_employee_id',
    'start_date',
    'end_date',
    'daily_rate',
    'currency',
    'reason',
    'status',
    'notes',
    'created_by',
])]
class TemporaryReplacement extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'daily_rate' => 'decimal:2',
            'status' => TemporaryReplacementStatus::class,
        ];
    }

    public function workAssignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeWorkAssignment::class);
    }

    public function replacementEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'replacement_employee_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TemporaryReplacementPayment::class)->latest('payment_date');
    }
}
