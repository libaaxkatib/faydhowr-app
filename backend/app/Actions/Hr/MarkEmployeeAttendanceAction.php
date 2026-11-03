<?php

namespace App\Actions\Hr;

use App\Enums\AttendanceStatus;
use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\InputOwnership;
use App\Support\Hr\Payroll\PayrollPeriodLock;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 4: unlike every other ledger action in
 * this app, a single day is one fact - marking the same employee+date again
 * corrects it in place (updateOrCreate) rather than creating a superseding
 * row, per the employee_attendances migration's unique constraint.
 */
class MarkEmployeeAttendanceAction
{
    /**
     * @param  bool  $companyProvided  the request named a company (null then means "no company": the office roster)
     * @param  int|null  $companyId  the company this day's mark belongs to
     */
    public function handle(Employee $employee, string $date, AttendanceStatus $status, ?string $notes, Admin $actor, bool $companyProvided = false, ?int $companyId = null): EmployeeAttendance
    {
        // An absence is charged to ONE company (each company owns its own payroll): the one the employee works for that day, or the one chosen when
        // they work for several. The day is one fact PER COMPANY, so a day can be marked for Company A without touching Company B.
        $company = InputOwnership::resolve($employee->id, $date, $date, $companyProvided, $companyId);
        app(PayrollPeriodLock::class)->assertDateEditable($date, 'Attendance on this date', $employee->id, null, InputOwnership::key($company));

        if ($employee->status !== EmployeeStatus::Active) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$employee->full_name}' must be Active to record attendance.",
                    'EMPLOYEE_NOT_ACTIVE',
                    422,
                ),
            );
        }

        $attendance = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->when($company === null, fn ($q) => $q->whereNull('client_company_id'), fn ($q) => $q->where('client_company_id', $company))
            ->first() ?? new EmployeeAttendance(['employee_id' => $employee->id, 'date' => $date, 'client_company_id' => $company]);

        $attendance->fill(['status' => $status, 'notes' => $notes, 'recorded_by' => $actor->id]);
        $attendance->save();

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Attendance for '{$employee->full_name}' on {$date} marked {$status->label()}.",
            entityType: Employee::class,
            entityId: $employee->id,
            metadata: ['date' => $date, 'status' => $status->value, 'client_company_id' => $company],
        ));

        return $attendance->load('recordedBy');
    }
}
