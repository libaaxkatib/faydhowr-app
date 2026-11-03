<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeePenalty;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\InputOwnership;
use App\Support\Hr\Payroll\PayrollPeriodLock;
use Carbon\CarbonImmutable;
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
        // The penalty belongs to ONE company (each company owns its own payroll): the one the employee works for, or the one the person recording it chose.
        $company = InputOwnership::resolve($employee->id, $data['payroll_period'].'-01', CarbonImmutable::parse($data['payroll_period'].'-01')->endOfMonth()->toDateString(), array_key_exists('client_company_id', $data), $data['client_company_id'] ?? null);
        app(PayrollPeriodLock::class)->assertMonthEditable($data['payroll_period'], 'The payroll month of this record', $employee->id, InputOwnership::key($company));

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
            'client_company_id' => $company,
            'employee_id' => $employee->id,
            'recorded_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Penalty of {$penalty->deduction_amount} {$penalty->currency} recorded for '{$employee->full_name}' ({$penalty->payroll_period}).",
            entityType: Employee::class,
            entityId: $employee->id,
            metadata: ['penalty_id' => $penalty->id, 'payroll_period' => $penalty->payroll_period, 'client_company_id' => $penalty->client_company_id],
        ));

        return $employee->load('penalties.recordedBy', 'penalties.clientCompany');
    }
}
