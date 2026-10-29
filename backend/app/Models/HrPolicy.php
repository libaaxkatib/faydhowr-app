<?php

namespace App\Models;

use App\Enums\AdminRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['key', 'enabled', 'changed_by', 'reason'])]
class HrPolicy extends Model
{
    /** One employee may hold concurrent assignments at more than one company. OFF unless the Super Admin turns it on. */
    public const MULTI_COMPANY_ASSIGNMENTS = 'multi_company_assignments';

    /** @var array<string, array{label: string, description: string, changeable_by: list<AdminRole>}> */
    public const CATALOG = [
        self::MULTI_COMPANY_ASSIGNMENTS => [
            'label' => 'Allow one employee to work for more than one company',
            'description' => 'Off: an employee can hold one assignment at a time. On: an employee may hold assignments at different companies at the same time; each keeps its own company, position, dates, salary and history.',
            // Owner decision 2026-10-29: only the Super Admin manages this switch; the HR Manager does not see it and the server refuses the HR
            // Manager's change (this replaces the Phase 2 decision that let the HR Manager switch it). Its value is never changed by code.
            'changeable_by' => [AdminRole::SuperAdmin],
        ],
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'changed_by');
    }

    /** May this role switch this policy? (Anyone else - including a Manager holding hr.manage directly - may only read it.) */
    public static function roleMayChange(string $key, AdminRole $role): bool
    {
        return in_array($role, self::CATALOG[$key]['changeable_by'] ?? [AdminRole::SuperAdmin], true);
    }

    /** A policy with no row is OFF. */
    public static function isEnabled(string $key): bool
    {
        return (bool) static::query()->where('key', $key)->value('enabled');
    }
}
