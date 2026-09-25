<?php

namespace App\Models;

use App\Enums\EmployeeGender;
use App\Enums\WorkforceRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'work_location_id',
    'employee_category_id',
    'position_id',
    'gender_requirement',
    'quantity_needed',
    'status',
    'requested_date',
    'notes',
    'created_by',
])]
class WorkforceRequest extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'gender_requirement' => EmployeeGender::class,
            'quantity_needed' => 'integer',
            'status' => WorkforceRequestStatus::class,
            'requested_date' => 'date',
        ];
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function employeeCategory(): BelongsTo
    {
        return $this->belongsTo(EmployeeCategory::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(WorkforceRequestMatch::class)->latest('created_at');
    }
}
