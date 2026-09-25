<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use Illuminate\Support\Facades\DB;

class UpdateEmployeeStatusAction
{
    public function handle(Employee $employee, EmployeeStatus $status, ?string $note, Admin $actor): Employee
    {
        return DB::transaction(function () use ($employee, $status, $note, $actor) {
            $from = $employee->status;

            $employee->update([
                'status' => $status,
                'waiting_since' => $from === EmployeeStatus::Waiting && $status !== EmployeeStatus::Waiting
                    ? null
                    : $employee->waiting_since,
            ]);

            EmployeeStatusHistory::query()->create([
                'employee_id' => $employee->id,
                'from_status' => $from,
                'to_status' => $status,
                'changed_by' => $actor->id,
                'note' => $note,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Employee '{$employee->full_name}' status changed from {$from->value} to {$status->value}.",
                entityType: Employee::class,
                entityId: $employee->id,
                metadata: ['from_status' => $from->value, 'to_status' => $status->value],
            ));

            return $employee->load(['category', 'department', 'position', 'statusHistories.changedBy']);
        });
    }
}
