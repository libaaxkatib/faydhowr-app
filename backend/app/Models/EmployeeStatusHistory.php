<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'from_status', 'to_status', 'changed_by', 'note'])]
class EmployeeStatusHistory extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'from_status' => EmployeeStatus::class,
            'to_status' => EmployeeStatus::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'changed_by');
    }
}
