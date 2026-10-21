<?php

namespace App\Models;

use App\Support\Hr\EmployeeCategoryName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description'])]
class EmployeeCategory extends Model
{
    use HasFactory;

    /** The category every person registered through the Office Staff workflow gets (seeded by a migration). */
    public const OFFICE_STAFF = 'Office Staff';

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    /** The Office Staff category, found case-insensitively so it matches the migration's own "already exists" check. */
    public static function officeStaff(): ?self
    {
        return static::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(self::OFFICE_STAFF)])->first();
    }

    /** Categories that can still be chosen for new registrations and requests. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** The Office Staff category is owned by the Office Staff workflow: it is never renamed, archived or deleted. */
    public function isSystem(): bool
    {
        return EmployeeCategoryName::key($this->name) === EmployeeCategoryName::key(self::OFFICE_STAFF);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
