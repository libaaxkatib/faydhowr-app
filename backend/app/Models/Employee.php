<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'employee_number',
    'full_name',
    'phone',
    'alternate_phone',
    'location',
    'age',
    'marital_status',
    'lives_with',
    'reference_name',
    'employee_category_id',
    'department_id',
    'position_id',
    'status',
    'guarantor_confirmed_at',
    'application_date',
    'joining_date',
    'experience',
    'training_fee_amount',
    'training_fee_status',
    'source',
    'notes',
    'created_by',
])]
class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => EmployeeStatus::class,
            'guarantor_confirmed_at' => 'datetime',
            'application_date' => 'date',
            'joining_date' => 'date',
            'training_fee_amount' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EmployeeCategory::class, 'employee_category_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(EmployeeStatusHistory::class)->latest('created_at');
    }

    public function practicalAssessments(): HasMany
    {
        return $this->hasMany(EmployeePracticalAssessment::class)->latest('assessment_date');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class)->latest('created_at');
    }
}
