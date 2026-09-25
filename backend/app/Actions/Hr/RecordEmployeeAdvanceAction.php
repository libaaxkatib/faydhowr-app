<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 5 "Salary Advance": a manual ledger, no
 * approval workflow, no repayment/installment engine - a row's existence IS
 * the official advance record. Multiple advances per employee per month are
 * explicitly allowed. Kept entirely separate from penalties/payments/leaves/
 * performance/attendance.
 */
class RecordEmployeeAdvanceAction
{
    public function handle(Employee $employee, array $data, Admin $actor): Employee
    {
        if ($employee->status !== EmployeeStatus::Active) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$employee->full_name}' must be Active to record a salary advance.",
                    'EMPLOYEE_NOT_ACTIVE',
                    422,
                ),
            );
        }

        $advance = EmployeeAdvance::query()->create([
            ...$data,
            'employee_id' => $employee->id,
            'recorded_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Salary advance of {$advance->amount} {$advance->currency} recorded for '{$employee->full_name}' ({$advance->payroll_period}).",
            entityType: Employee::class,
            entityId: $employee->id,
            metadata: ['advance_id' => $advance->id, 'payroll_period' => $advance->payroll_period],
        ));

        return $employee->load('advances.recordedBy');
    }
}
