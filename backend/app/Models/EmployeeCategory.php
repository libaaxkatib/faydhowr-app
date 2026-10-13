<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description'])]
class EmployeeCategory extends Model
{
    use HasFactory;

    /** The category every person registered through the Office Staff workflow gets (seeded by a migration). */
    public const OFFICE_STAFF = 'Office Staff';

    /** The Office Staff category, found case-insensitively so it matches the migration's own "already exists" check. */
    public static function officeStaff(): ?self
    {
        return static::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(self::OFFICE_STAFF)])->first();
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
