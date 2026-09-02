<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use Illuminate\Support\Facades\Date;

/**
 * "DAMIIN WALI KEENIN" (guarantor not yet brought) from the real registration
 * spreadsheet — modeled as a nullable timestamp rather than a 6th pipeline
 * status, since the SRS's own status list (docs/HRM_MARKETING_SRS.md §27)
 * doesn't name a separate guarantor-blocked state. See the HRM implementation
 * report for this reconciliation note.
 */
class ConfirmEmployeeGuarantorAction
{
    public function handle(Employee $employee, Admin $actor): Employee
    {
        $employee->update(['guarantor_confirmed_at' => Date::now()]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Guarantor confirmed for employee '{$employee->full_name}' ({$employee->employee_number}).",
            entityType: Employee::class,
            entityId: $employee->id,
        ));

        return $employee;
    }
}
