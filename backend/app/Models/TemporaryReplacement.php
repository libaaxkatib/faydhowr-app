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
    'planned_end_date',
    'daily_rate',
    'currency',
    'reason',
    'status',
    'approved_at',
    'approved_by',
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
            'planned_end_date' => 'date',
            'daily_rate' => 'decimal:2',
            'status' => TemporaryReplacementStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function workAssignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeWorkAssignment::class);
    }

    /**
     * Ends the cover on a last day. When the cover had a planned end date and is ended EARLIER, the plan is kept in planned_end_date (once) so
     * the history shows both the plan and what actually happened; end_date is always the last day that counts (docs/HR_PAYROLL_RULES.md N10).
     */
    public function endOn(string $lastDay, ?string $notes = null): void
    {
        $plannedBefore = $this->planned_end_date?->toDateString();
        $previousEnd = $this->end_date?->toDateString();

        $this->update([
            'end_date' => $lastDay,
            'status' => TemporaryReplacementStatus::Ended,
            'planned_end_date' => $plannedBefore ?? ($previousEnd !== null && $previousEnd > $lastDay ? $previousEnd : null),
            'notes' => $notes ?? $this->notes,
        ]);
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

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    public function workdays(): HasMany
    {
        return $this->hasMany(TemporaryReplacementWorkday::class)->orderBy('work_date');
    }
}
