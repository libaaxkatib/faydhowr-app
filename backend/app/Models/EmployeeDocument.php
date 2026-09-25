<?php

namespace App\Models;

use App\Enums\EmployeeDocumentVerificationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'admin_id',
    'employee_document_category_id',
    'file_name',
    'file_type',
    'file_size',
    'file_path',
    'document_number',
    'verification_status',
    'verified_at',
    'verified_by',
    'expiry_date',
    'superseded_by_document_id',
    'is_current',
])]
class EmployeeDocument extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'verification_status' => EmployeeDocumentVerificationStatus::class,
            'verified_at' => 'datetime',
            'expiry_date' => 'date',
            'is_current' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocumentCategory::class, 'employee_document_category_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'verified_by');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'superseded_by_document_id');
    }
}
