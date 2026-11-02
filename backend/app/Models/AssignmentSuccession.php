<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The replacement HR/Management chose when a Permanent assignment ended (docs/HR_PAYROLL_RULES.md N1-N3). One row per departing assignment.
 */
#[Fillable([
    'departing_assignment_id',
    'successor_type',
    'successor_employee_id',
    'temporary_replacement_id',
    'successor_assignment_id',
    'departing_last_day',
    'successor_start_date',
    'employment_type_from',
    'employment_type_to',
    'salary_amount',
    'salary_currency',
    'salary_frequency',
    'salary_source',
    'trigger',
    'chosen_by',
])]
class AssignmentSuccession extends Model
{
    public const TYPE_TEMPORARY = 'temporary';

    /** A person who was on the HR Waiting List when chosen. Kept as its own value so earlier records and clients stay valid. */
    public const TYPE_WAITING_LIST = 'waiting_list';

    /** Any other person in the HR system, chosen by HR (any status, any category). The Waiting List is no longer a requirement. */
    public const TYPE_PERSON = 'person';

    public const TRIGGER_STOP = 'stop';

    public const TRIGGER_END = 'end';

    public const TRIGGER_EDIT_END_DATE = 'edit_end_date';

    public const TRIGGER_SEPARATION = 'separation';

    /** @return list<string> */
    public static function types(): array
    {
        return [self::TYPE_TEMPORARY, self::TYPE_PERSON, self::TYPE_WAITING_LIST];
    }

    protected function casts(): array
    {
        return [
            'departing_last_day' => 'date',
            'successor_start_date' => 'date',
            'salary_amount' => 'decimal:2',
        ];
    }

    public function departingAssignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeWorkAssignment::class, 'departing_assignment_id');
    }

    public function successorAssignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeWorkAssignment::class, 'successor_assignment_id');
    }

    public function successor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'successor_employee_id');
    }

    public function temporaryReplacement(): BelongsTo
    {
        return $this->belongsTo(TemporaryReplacement::class);
    }

    public function chosenBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'chosen_by');
    }
}
