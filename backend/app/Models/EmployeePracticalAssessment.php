<?php

namespace App\Models;

use App\Enums\PracticalAssessmentResult;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'assessed_by', 'assessment_date', 'result', 'notes'])]
class EmployeePracticalAssessment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'assessment_date' => 'date',
            'result' => PracticalAssessmentResult::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assessed_by');
    }
}
