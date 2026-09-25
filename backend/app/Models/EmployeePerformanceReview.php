<?php

namespace App\Models;

use App\Enums\PerformanceRating;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'review_date', 'rating', 'notes', 'reviewed_by'])]
class EmployeePerformanceReview extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'review_date' => 'date',
            'rating' => PerformanceRating::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }
}
