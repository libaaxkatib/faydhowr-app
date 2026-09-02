<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'admin_id', 'file_name', 'file_type', 'file_size', 'file_path'])]
class EmployeeDocument extends Model
{
    use HasFactory;

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
