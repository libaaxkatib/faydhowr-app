<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description'])]
class EmployeeDocumentCategory extends Model
{
    use HasFactory;

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }
}
