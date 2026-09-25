<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeePayment;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 4 "Payment records": a manual ledger,
 * structurally identical to Phase 3's RecordTemporaryReplacementPaymentAction
 * - a row's existence IS the paid event, no pending/paid state machine.
 * Salary calculation is explicitly Phase 5's job.
 */
class RecordEmployeePaymentAction
{
    public function handle(Employee $employee, array $data, Admin $actor): Employee
    {
        if ($employee->status !== EmployeeStatus::Active) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$employee->full_name}' must be Active to record a payment.",
                    'EMPLOYEE_NOT_ACTIVE',
                    422,
                ),
            );
        }

        $payment = EmployeePayment::query()->create([
            ...$data,
            'employee_id' => $employee->id,
            'paid_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Payment of {$payment->amount} {$payment->currency} recorded for '{$employee->full_name}'.",
            entityType: Employee::class,
            entityId: $employee->id,
            metadata: ['payment_id' => $payment->id],
        ));

        return $employee->load('payments.paidBy');
    }
}
