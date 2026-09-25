<?php

namespace App\Models;

use App\Enums\EmployeeContractStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'contract_type',
    'contract_number',
    'date_issued',
    'start_date',
    'end_date',
    'status',
    'signed_date',
    'signed_document_id',
    'notes',
    'created_by',
])]
class EmployeeContract extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date_issued' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'signed_date' => 'date',
            'status' => EmployeeContractStatus::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function signedDocument(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'signed_document_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}
