<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Enums\LeaveType;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\InputOwnership;
use App\Support\Hr\Payroll\PayrollPeriodLock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Records a leave period. Annual, sick and other leave are effective as soon as
 * they are recorded, exactly as before. UNPAID leave starts PENDING: it reduces
 * payroll only after it is approved (DecideEmployeeLeaveAction). Rows are never
 * deleted - every leave period and every decision stays as history.
 *
 * Unpaid leave cannot overlap another pending/approved unpaid leave of the same
 * employee (that would double-deduct the shared days), and cannot touch a
 * payroll month that is already approved or finalized.
 */
class RecordEmployeeLeaveAction
{
    public function __construct(private readonly PayrollPeriodLock $lock = new PayrollPeriodLock) {}

    public function handle(Employee $employee, array $data, Admin $actor): Employee
    {
        if ($employee->status !== EmployeeStatus::Active) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$employee->full_name}' must be Active to record leave.",
                    'EMPLOYEE_NOT_ACTIVE',
                    422,
                ),
            );
        }

        $isUnpaid = ($data['leave_type'] ?? null) === LeaveType::Unpaid->value;
        $company = null;

        // Only UNPAID leave touches payroll, so only it needs a company: the one the employee works for on those days, or the one the person
        // recording it chose (several companies: required). It reduces THAT company's pay and no other's.
        if ($isUnpaid) {
            $company = InputOwnership::resolve($employee->id, $data['start_date'], $data['end_date'], array_key_exists('client_company_id', $data), $data['client_company_id'] ?? null);
            $this->guardUnpaid($employee, $data['start_date'], $data['end_date'], $company);
        }

        $leave = EmployeeLeave::query()->create([
            ...$data,
            'client_company_id' => $company,
            'employee_id' => $employee->id,
            'recorded_by' => $actor->id,
            'approval_status' => $isUnpaid ? 'pending' : 'approved',
            'decided_by' => $isUnpaid ? null : $actor->id,
            'decided_at' => $isUnpaid ? null : now(),
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Leave recorded for '{$employee->full_name}' ({$leave->leave_type->label()})".($isUnpaid ? ' - awaiting approval.' : '.'),
            entityType: Employee::class,
            entityId: $employee->id,
            metadata: ['leave_id' => $leave->id, 'approval_status' => $leave->approval_status],
        ));

        return $employee->load('leaves.recordedBy', 'leaves.decidedBy', 'leaves.clientCompany');
    }

    private function guardUnpaid(Employee $employee, string $start, string $end, ?int $company): void
    {
        // Two companies each have their own payroll, so the same days can be unpaid leave for each; the same company cannot count them twice (a leave
        // from before company ownership, which names no company, still counts against every company).
        $overlap = EmployeeLeave::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type', LeaveType::Unpaid)
            ->where(fn ($q) => $company === null ? $q->whereNull('client_company_id') : $q->where('client_company_id', $company)->orWhereNull('client_company_id'))
            ->whereIn('approval_status', ['pending', 'approved'])
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->exists();

        if ($overlap) {
            throw new HttpResponseException(ApiResponse::error(
                'This employee already has unpaid leave that overlaps these dates.',
                'LEAVE_OVERLAPS_UNPAID_LEAVE',
                422,
            ));
        }

        $this->assertMonthsOpen($start, $end, $employee->id, InputOwnership::key($company));
    }

    /**
     * Every calendar month touched by the leave must still be open for payroll - for the leave's OWN company.
     *
     * @param  string|null  $ownerKey  the leave's company key (InputOwnership::key); null for a leave from before company ownership (then judged by where the employee works)
     */
    public static function assertMonthsOpenFor(PayrollPeriodLock $lock, string $start, string $end, ?int $employeeId = null, ?string $ownerKey = null): void
    {
        for ($month = CarbonImmutable::parse($start)->startOfMonth(); $month->lte(CarbonImmutable::parse($end)); $month = $month->addMonth()) {
            // The days of the leave that fall in this month.
            $lock->assertRangeEditable($month->toDateString(), $start, $end, 'Unpaid leave', $employeeId, $ownerKey);
        }
    }

    private function assertMonthsOpen(string $start, string $end, ?int $employeeId = null, ?string $ownerKey = null): void
    {
        self::assertMonthsOpenFor($this->lock, $start, $end, $employeeId, $ownerKey);
    }
}
