<?php

namespace App\Models;

use App\Enums\AssignmentType;
use App\Enums\SalaryFrequency;
use App\Enums\WorkAssignmentStatus;
use App\Support\Hr\Payroll\PayrollMoney;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

#[Fillable([
    'employee_id',
    'work_location_id',
    'client_company_id',
    'replaces_assignment_id',
    'position_id',
    'assignment_type',
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
            'assignment_type' => AssignmentType::class,
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

    /**
     * The company recorded directly on the assignment. A work location is
     * optional, so this is the authoritative company link; for an office
     * assignment it is null.
     */
    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    /** The Permanent assignment this one takes over from (null when it replaces nobody). */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_assignment_id');
    }

    public function successors(): HasMany
    {
        return $this->hasMany(self::class, 'replaces_assignment_id');
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

    public function salaryHistories(): HasMany
    {
        return $this->hasMany(AssignmentSalaryHistory::class, 'work_assignment_id')->orderBy('effective_from');
    }

    /**
     * Effective-dated MONTHLY salary periods for payroll. A period runs until
     * the day before the next period starts, so there are no gaps or overlaps.
     * Any non-monthly row makes payroll refuse rather than guess a conversion.
     *
     * @return list<array{from: string, to: ?string, monthly_cents: int}>
     */
    public function monthlySalaryPeriods(): array
    {
        $rows = $this->salaryHistories()->get()->values();
        $periods = [];

        foreach ($rows as $index => $row) {
            // Payroll is USD only. A salary in another currency is refused, never treated as dollars and never converted: no
            // exchange rule has been approved.
            if (strtoupper((string) $row->currency) !== PayrollMoney::CURRENCY) {
                throw new RuntimeException("Assignment #{$this->id} has a salary in {$row->currency}; payroll supports USD only until a conversion rule is approved, so no payroll line is calculated.");
            }

            if ($row->frequency !== SalaryFrequency::Monthly) {
                throw new RuntimeException("Assignment #{$this->id} has a non-monthly salary ({$row->frequency->value}); payroll supports monthly salaries only.");
            }

            $next = $rows[$index + 1] ?? null;
            $periods[] = [
                'from' => $row->effective_from->toDateString(),
                'to' => $next?->effective_from->copy()->subDay()->toDateString(),
                'monthly_cents' => PayrollMoney::toCents($row->amount),
            ];
        }

        return $periods;
    }
}
