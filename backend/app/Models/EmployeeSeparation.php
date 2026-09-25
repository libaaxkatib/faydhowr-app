<?php

namespace App\Models;

use App\Enums\EmployeeSeparationReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'reason', 'separation_date', 'rehire_eligible', 'notes', 'separated_by'])]
class EmployeeSeparation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'reason' => EmployeeSeparationReason::class,
            'separation_date' => 'date',
            'rehire_eligible' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function separatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'separated_by');
    }
}
