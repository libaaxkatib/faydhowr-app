<?php

namespace App\Models;

use App\Enums\SalaryFrequency;
use App\Enums\WorkAssignmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'employee_id',
    'work_location_id',
    'position_id',
    'start_date',
    'end_date',
    'salary_amount',
    'salary_currency',
    'salary_frequency',
    'status',
    'notes',
    'created_by',
])]
class EmployeeWorkAssignment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'salary_amount' => 'decimal:2',
            'salary_frequency' => SalaryFrequency::class,
            'status' => WorkAssignmentStatus::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function temporaryReplacements(): HasMany
    {
        return $this->hasMany(TemporaryReplacement::class);
    }
}
