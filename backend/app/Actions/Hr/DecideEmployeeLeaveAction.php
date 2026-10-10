<?php

namespace App\Actions\Hr;

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Enums\LeaveType;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollPeriodLock;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Approves or rejects an UNPAID leave. Only approved unpaid leave reduces pay.
 *
 *  - The approver must differ from the person who recorded the leave, unless the
 *    approver is a Super Admin.
 *  - Rejecting needs a note. A pending or approved leave can be rejected (for
 *    example if recorded by mistake) as long as its payroll months are still
 *    open; the row itself stays for history and the audit log keeps every decision.
 *  - Once a payroll month is approved or finalized the leave is frozen; pay for
 *    that month can only change through a payroll adjustment.
 */
class DecideEmployeeLeaveAction
{
    public function __construct(private readonly PayrollPeriodLock $lock = new PayrollPeriodLock) {}

    public function approve(EmployeeLeave $leave, ?string $note, Admin $actor): EmployeeLeave
    {
        $this->guard($leave);

        if ($leave->approval_status === 'approved') {
            $this->fail('This leave is already approved.', 'LEAVE_ALREADY_APPROVED');
        }

        if ((int) $leave->recorded_by === $actor->id && $actor->role !== AdminRole::SuperAdmin) {
            $this->fail('The person who recorded this leave cannot also approve it.', 'APPROVER_MUST_DIFFER_FROM_RECORDER');
        }

        return $this->decide($leave, 'approved', $note, $actor);
    }

    public function reject(EmployeeLeave $leave, string $note, Admin $actor): EmployeeLeave
    {
        $this->guard($leave);

        if ($leave->approval_status === 'rejected') {
            $this->fail('This leave is already rejected.', 'LEAVE_ALREADY_REJECTED');
        }

        return $this->decide($leave, 'rejected', $note, $actor);
    }

    private function guard(EmployeeLeave $leave): void
    {
        if ($leave->leave_type !== LeaveType::Unpaid) {
            $this->fail('Only unpaid leave needs a decision; other leave types are effective when recorded.', 'LEAVE_NOT_UNPAID');
        }

        RecordEmployeeLeaveAction::assertMonthsOpenFor($this->lock, $leave->start_date->toDateString(), $leave->end_date->toDateString());
    }

    private function decide(EmployeeLeave $leave, string $status, ?string $note, Admin $actor): EmployeeLeave
    {
        $previous = $leave->approval_status;
        $leave->update(['approval_status' => $status, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);

        $employee = Employee::withTrashed()->find($leave->employee_id);

        event(AuditEvent::record(
            action: $status === 'approved' ? AuditAction::Approve : AuditAction::Cancel,
            admin: $actor,
            description: "Unpaid leave #{$leave->id} for '{$employee?->full_name}' {$status}.",
            entityType: EmployeeLeave::class,
            entityId: $leave->id,
            metadata: ['previous_status' => $previous, 'note' => $note, 'start_date' => $leave->start_date->toDateString(), 'end_date' => $leave->end_date->toDateString()],
        ));

        return $leave->load('recordedBy', 'decidedBy');
    }

    private function fail(string $message, string $code): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422));
    }
}
