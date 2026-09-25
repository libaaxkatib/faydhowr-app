<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeePenalty;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 5 "Penalty / Salary Deduction History":
 * a manual ledger, no approval workflow - a row's existence IS the official
 * penalty record. Structurally identical to Phase 4's
 * RecordEmployeePaymentAction. Kept entirely separate from payments/leaves/
 * performance/attendance - its own table, its own relation, its own card.
 */
class RecordEmployeePenaltyAction
{
    public function handle(Employee $employee, array $data, Admin $actor): Employee
    {
        if ($employee->status !== EmployeeStatus::Active) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$employee->full_name}' must be Active to record a penalty.",
                    'EMPLOYEE_NOT_ACTIVE',
                    422,
                ),
            );
        }

        $penalty = EmployeePenalty::query()->create([
            ...$data,
            'employee_id' => $employee->id,
            'recorded_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Penalty of {$penalty->deduction_amount} {$penalty->currency} recorded for '{$employee->full_name}' ({$penalty->payroll_period}).",
            entityType: Employee::class,
            entityId: $employee->id,
            metadata: ['penalty_id' => $penalty->id, 'payroll_period' => $penalty->payroll_period],
        ));

        return $employee->load('penalties.recordedBy');
    }
}
