<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\InputOwnership;
use App\Support\Hr\Payroll\PayrollPeriodLock;
use Carbon\CarbonImmutable;
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
        // The advance belongs to ONE company (each company owns its own payroll): the one the employee works for, or the one the person recording it chose.
        $company = InputOwnership::resolve($employee->id, $data['payroll_period'].'-01', CarbonImmutable::parse($data['payroll_period'].'-01')->endOfMonth()->toDateString(), array_key_exists('client_company_id', $data), $data['client_company_id'] ?? null);
        app(PayrollPeriodLock::class)->assertMonthEditable($data['payroll_period'], 'The payroll month of this record', $employee->id, InputOwnership::key($company));

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
            'client_company_id' => $company,
            'employee_id' => $employee->id,
            'recorded_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Salary advance of {$advance->amount} {$advance->currency} recorded for '{$employee->full_name}' ({$advance->payroll_period}).",
            entityType: Employee::class,
            entityId: $employee->id,
            metadata: ['advance_id' => $advance->id, 'payroll_period' => $advance->payroll_period, 'client_company_id' => $advance->client_company_id],
        ));

        return $employee->load('advances.recordedBy', 'advances.clientCompany');
    }
}
