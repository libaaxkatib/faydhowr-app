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

        if ($isUnpaid) {
            $this->guardUnpaid($employee, $data['start_date'], $data['end_date']);
        }

        $leave = EmployeeLeave::query()->create([
            ...$data,
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

        return $employee->load('leaves.recordedBy', 'leaves.decidedBy');
    }

    private function guardUnpaid(Employee $employee, string $start, string $end): void
    {
        $overlap = EmployeeLeave::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type', LeaveType::Unpaid)
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

        $this->assertMonthsOpen($start, $end);
    }

    /**
     * Every calendar month touched by the leave must still be open for payroll.
     */
    public static function assertMonthsOpenFor(PayrollPeriodLock $lock, string $start, string $end): void
    {
        for ($month = CarbonImmutable::parse($start)->startOfMonth(); $month->lte(CarbonImmutable::parse($end)); $month = $month->addMonth()) {
            $lock->assertDateEditable($month->toDateString(), 'Unpaid leave');
        }
    }

    private function assertMonthsOpen(string $start, string $end): void
    {
        self::assertMonthsOpenFor($this->lock, $start, $end);
    }
}
