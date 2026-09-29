<?php

namespace App\Models;

use App\Enums\EmployeeGender;
use App\Enums\EmployeePipelineStage;
use App\Enums\EmployeeStatus;
use App\Enums\WorkAssignmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
    'secondary_contact_name',
    'secondary_contact_phone',
    'gender',
    'profile_picture_document_id',
    'employee_category_id',
    'category_specialization',
    'department_id',
    'position_id',
    'status',
    'pipeline_stage',
    'guarantor_confirmed_at',
    'guarantor_needed',
    'waiting_since',
    'is_supervisor',
    'supervisor_since',
    'application_date',
    'joining_date',
    'experience',
    'training_fee_amount',
    'training_fee_status',
    'source',
    'notes',
    'profile_complete',
    'created_by',
])]
class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'gender' => EmployeeGender::class,
            'status' => EmployeeStatus::class,
            'pipeline_stage' => EmployeePipelineStage::class,
            'guarantor_confirmed_at' => 'datetime',
            'guarantor_needed' => 'boolean',
            'waiting_since' => 'datetime',
            'is_supervisor' => 'boolean',
            'supervisor_since' => 'datetime',
            'application_date' => 'date',
            'joining_date' => 'date',
            'training_fee_amount' => 'decimal:2',
            'profile_complete' => 'boolean',
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

    public function workAssignments(): HasMany
    {
        return $this->hasMany(EmployeeWorkAssignment::class)->latest('start_date');
    }

    public function activeWorkAssignments(): HasMany
    {
        return $this->hasMany(EmployeeWorkAssignment::class)
            ->where('status', WorkAssignmentStatus::Active)
            ->latest('start_date');
    }

    public function guarantor(): HasOne
    {
        return $this->hasOne(EmployeeGuarantor::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmployeeContract::class)->latest('created_at');
    }

    public function currentContract(): HasOne
    {
        return $this->hasOne(EmployeeContract::class)->latestOfMany('created_at');
    }

    public function uniform(): HasOne
    {
        return $this->hasOne(EmployeeUniform::class);
    }

    public function profilePictureDocument(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'profile_picture_document_id');
    }

    public function workforceRequestMatches(): HasMany
    {
        return $this->hasMany(WorkforceRequestMatch::class)->latest('created_at');
    }

    public function separations(): HasMany
    {
        return $this->hasMany(EmployeeSeparation::class)->latest('separation_date');
    }

    public function historicalCompletions(): HasMany
    {
        return $this->hasMany(EmployeeHistoricalCompletion::class);
    }

    public function latestSeparation(): HasOne
    {
        return $this->hasOne(EmployeeSeparation::class)->latestOfMany('separation_date');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(EmployeeAttendance::class)->latest('date');
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(EmployeeLeave::class)->latest('start_date');
    }

    public function performanceReviews(): HasMany
    {
        return $this->hasMany(EmployeePerformanceReview::class)->latest('review_date');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(EmployeePayment::class)->latest('payment_date');
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(EmployeePenalty::class)->latest('penalty_date');
    }

    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class)->latest('advance_date');
    }

    public function trainingParticipations(): HasMany
    {
        return $this->hasMany(TrainingBatchParticipant::class);
    }
}
