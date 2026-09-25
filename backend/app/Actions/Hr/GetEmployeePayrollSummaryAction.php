<?php

namespace App\Actions\Hr;

use App\Enums\AttendanceStatus;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAttendance;
use App\Models\EmployeePenalty;
use App\Models\EmployeeWorkAssignment;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 5 (Payroll & Accounting): a pure,
 * read-only calculation - no writes, no persisted "payroll run". HR reviews
 * this, then records the actual payment via the existing (Phase 4)
 * EmployeePayment ledger. Net Payable = Monthly Salary - Absence Deduction
 * - Penalty Deduction - Salary Advance Deduction, returned as-is
 * (uncapped, un-floored) - negative Net Payable handling is an explicitly
 * deferred management decision, not invented here. Overtime is never
 * included in this payload for any assignment - the client-company rule is
 * "no overtime, ever"; the office rule is "formula pending management
 * decision" - the frontend renders a static pending note for office
 * assignments only, never a fabricated number.
 */
class GetEmployeePayrollSummaryAction
{
    public function handle(Employee $employee, EmployeeWorkAssignment $assignment, string $payrollPeriod): array
    {
        if ((int) $assignment->employee_id !== (int) $employee->id) {
            throw new HttpResponseException(
                ApiResponse::error(
                    'This work assignment does not belong to this employee.',
                    'WORK_ASSIGNMENT_NOT_FOUND_FOR_EMPLOYEE',
                    422,
                ),
            );
        }

        $periodStart = CarbonImmutable::createFromFormat('Y-m-d', "{$payrollPeriod}-01");
        $daysInMonth = $periodStart->daysInMonth;
        $dailyRate = (float) $assignment->salary_amount / $daysInMonth;

        $absentDays = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->where('status', AttendanceStatus::Absent)
            ->whereYear('date', $periodStart->year)
            ->whereMonth('date', $periodStart->month)
            ->count();
        $absenceDeduction = $dailyRate * $absentDays;

        $penalties = EmployeePenalty::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period', $payrollPeriod)
            ->get();
        $penaltyDeduction = (float) $penalties->sum('deduction_amount');

        $advances = EmployeeAdvance::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_period', $payrollPeriod)
            ->get();
        $advanceDeduction = (float) $advances->sum('amount');

        $monthlySalary = (float) $assignment->salary_amount;
        $netPayable = $monthlySalary - $absenceDeduction - $penaltyDeduction - $advanceDeduction;

        return [
            'employee_id' => $employee->id,
            'work_assignment_id' => $assignment->id,
            'payroll_period' => $payrollPeriod,
            'location_type' => $assignment->workLocation?->location_type?->value,
            'monthly_salary' => number_format($monthlySalary, 2, '.', ''),
            'currency' => $assignment->salary_currency,
            'days_in_month' => $daysInMonth,
            'daily_rate' => number_format($dailyRate, 2, '.', ''),
            'absent_days' => $absentDays,
            'absence_deduction' => number_format($absenceDeduction, 2, '.', ''),
            'penalties' => $penalties->map(fn (EmployeePenalty $p) => [
                'id' => $p->id,
                'penalty_date' => $p->penalty_date?->toDateString(),
                'reason' => $p->reason,
                'deduction_amount' => $p->deduction_amount,
            ])->all(),
            'penalty_deduction' => number_format($penaltyDeduction, 2, '.', ''),
            'advances' => $advances->map(fn (EmployeeAdvance $a) => [
                'id' => $a->id,
                'advance_date' => $a->advance_date?->toDateString(),
                'reason' => $a->reason,
                'amount' => $a->amount,
            ])->all(),
            'advance_deduction' => number_format($advanceDeduction, 2, '.', ''),
            'net_payable' => number_format($netPayable, 2, '.', ''),
        ];
    }
}
