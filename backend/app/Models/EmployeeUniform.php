<?php

namespace App\Models;

use App\Enums\EmployeeUniformStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'status',
    'purchased_at',
    'received_at',
    'confirmed_at',
    'confirmed_by',
    'notes',
])]
class EmployeeUniform extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => EmployeeUniformStatus::class,
            'purchased_at' => 'date',
            'received_at' => 'date',
            'confirmed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'confirmed_by');
    }
}
