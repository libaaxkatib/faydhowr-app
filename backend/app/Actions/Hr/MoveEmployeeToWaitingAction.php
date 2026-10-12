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
 * Moves an EXISTING employee to Waiting because a confirmed waiting-list import matched them. It goes
 * through the transition guard in the waiting-import context (applicant, recruitment or practical to
 * waiting) and is never overridable here. It changes nothing but the status and the waiting clock, and
 * records the import in the status history and the audit log.
 */
class MoveEmployeeToWaitingAction
{
    public function __construct(private readonly EmployeeTransitionGuard $guard) {}

    public function handle(Employee $employee, string $note, Admin $actor, int $importBatchId): Employee
    {
        return DB::transaction(function () use ($employee, $note, $actor, $importBatchId): Employee {
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            $this->guard->authorize($from, EmployeeStatus::Waiting, EmployeeTransitionGuard::CONTEXT_WAITING_IMPORT, $actor);

            $previousStage = $locked->pipeline_stage?->value;

            $locked->update([
                'status' => EmployeeStatus::Waiting,
                'waiting_since' => now(),
                'pipeline_stage' => null,
            ]);

            EmployeeStatusHistory::query()->create([
                'employee_id' => $locked->id,
                'from_status' => $from,
                'to_status' => EmployeeStatus::Waiting,
                'changed_by' => $actor->id,
                'note' => $note,
                'source' => 'waiting_import',
                'import_batch_id' => $importBatchId,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Employee '{$locked->full_name}' moved from {$from->value} to waiting by waiting-list import #{$importBatchId}.",
                entityType: Employee::class,
                entityId: $locked->id,
                metadata: [
                    'from_status' => $from->value,
                    'to_status' => 'waiting',
                    'import_batch_id' => $importBatchId,
                    'previous_pipeline_stage' => $previousStage,
                ],
            ));

            return $locked;
        });
    }
}
