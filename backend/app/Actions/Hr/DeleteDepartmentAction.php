<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Support\Hr\ReferenceUsage;
use Illuminate\Support\Facades\DB;

class DeleteDepartmentAction
{
    /**
     * A department that positions or employees still point at is never deleted (the foreign keys are ON DELETE SET NULL,
     * so the database would otherwise clear them silently). Archived employees count. Check and delete run in one
     * transaction behind a row lock on the department - see DeletePositionAction for why that closes the race.
     */
    public function handle(Department $department, Admin $actor): void
    {
        [$name, $id] = DB::transaction(function () use ($department): array {
            $locked = Department::query()->whereKey($department->getKey())->lockForUpdate()->firstOrFail();

            $employees = Employee::withTrashed()->where('department_id', $locked->id)->count();
            $archived = Employee::onlyTrashed()->where('department_id', $locked->id)->count();

            $employeePhrase = ReferenceUsage::phrase($employees, 'employee');
            if ($employeePhrase !== null && $archived > 0) {
                $employeePhrase .= " ({$archived} archived)";
            }

            $usedBy = ReferenceUsage::join([
                ReferenceUsage::phrase(Position::query()->where('department_id', $locked->id)->count(), 'position'),
                $employeePhrase,
            ]);

            if ($usedBy !== null) {
                ReferenceUsage::refuse('department', 'DEPARTMENT_IN_USE', $usedBy);
            }

            $name = $locked->name;
            $id = $locked->id;
            $locked->delete();

            return [$name, $id];
        });

        event(AuditEvent::record(
            action: AuditAction::Delete,
            admin: $actor,
            description: "Department '{$name}' deleted.",
            entityType: Department::class,
            entityId: $id,
        ));
    }
}
