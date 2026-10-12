<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Support\Hr\EmployeeTransitionGuard;
use Illuminate\Support\Facades\DB;

/**
 * The status-update workflow. Every change is checked by the EmployeeTransitionGuard first: only the
 * normal lifecycle steps are allowed, except that a Super Admin may override with a written reason,
 * which is then recorded on the status history and in the audit log (actor, time, employee, previous
 * and new status).
 */
class UpdateEmployeeStatusAction
{
    public function __construct(private readonly EmployeeTransitionGuard $guard) {}

    public function handle(
        Employee $employee,
        EmployeeStatus $status,
        ?string $note,
        Admin $actor,
        ?string $overrideReason = null,
        string $source = 'status_update',
        ?int $importBatchId = null,
    ): Employee {
        return DB::transaction(function () use ($employee, $status, $note, $actor, $overrideReason, $source, $importBatchId) {
            // Re-read under a lock so two simultaneous requests cannot both pass the guard
            // against the same starting status.
            $locked = Employee::withTrashed()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            $usedOverride = $this->guard->authorize($from, $status, EmployeeTransitionGuard::CONTEXT_STATUS_UPDATE, $actor, $overrideReason);

            $locked->update([
                'status' => $status,
                'waiting_since' => $from === EmployeeStatus::Waiting && $status !== EmployeeStatus::Waiting
                    ? null
                    : $locked->waiting_since,
            ]);

            EmployeeStatusHistory::query()->create([
                'employee_id' => $locked->id,
                'from_status' => $from,
                'to_status' => $status,
                'changed_by' => $actor->id,
                'note' => $note,
                'override_reason' => $usedOverride,
                'source' => $usedOverride !== null ? 'override' : $source,
                'import_batch_id' => $importBatchId,
            ]);

            $metadata = ['from_status' => $from->value, 'to_status' => $status->value];

            if ($usedOverride !== null) {
                $metadata += ['override' => true, 'override_reason' => $usedOverride];
            }

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: $usedOverride !== null
                    ? "Employee '{$locked->full_name}' status changed from {$from->value} to {$status->value} by Super Admin override."
                    : "Employee '{$locked->full_name}' status changed from {$from->value} to {$status->value}.",
                entityType: Employee::class,
                entityId: $locked->id,
                metadata: $metadata,
            ));

            return $locked->load(['category', 'department', 'position', 'statusHistories.changedBy']);
        });
    }
}
