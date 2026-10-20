<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Support\Hr\EmployeeRegistrationWorkflow;
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
    public function __construct(
        private readonly EmployeeTransitionGuard $guard,
        private readonly EmployeeRegistrationWorkflow $workflow = new EmployeeRegistrationWorkflow,
    ) {}

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

            // A NEW registration cannot become Waiting / Approved / Active before the registration workflow is complete; only a Super
            // Admin can go around it, with a written reason that is recorded. Existing records are not held to this.
            $missing = $this->workflow->protects($status) && $this->workflow->blocks($locked) ? $this->workflow->missing($locked) : [];
            $usedOverride = $this->workflow->authorizeStatusChange($locked, $status, $actor, $overrideReason) ?? $usedOverride;

            // Becoming Active takes the person out of the recruitment pipeline: the stage is cleared on the record, and kept in the
            // history below (never invented, never deleted).
            $previousStage = $status === EmployeeStatus::Active ? $locked->pipeline_stage : null;

            $locked->update([
                'status' => $status,
                'pipeline_stage' => $status === EmployeeStatus::Active ? null : $locked->pipeline_stage,
                'waiting_since' => $from === EmployeeStatus::Waiting && $status !== EmployeeStatus::Waiting
                    ? null
                    : $locked->waiting_since,
            ]);

            EmployeeStatusHistory::query()->create([
                'employee_id' => $locked->id,
                'from_status' => $from,
                'to_status' => $status,
                'changed_by' => $actor->id,
                'note' => $previousStage === null ? $note : trim(($note ? $note.' ' : '')."Pipeline stage '{$previousStage->label()}' cleared because the person is now Active (the stage is kept here as history)."),
                'override_reason' => $usedOverride,
                'source' => $usedOverride !== null ? 'override' : $source,
                'import_batch_id' => $importBatchId,
            ]);

            $metadata = ['from_status' => $from->value, 'to_status' => $status->value];

            if ($previousStage !== null) {
                $metadata += ['previous_pipeline_stage' => $previousStage->value, 'pipeline_stage_cleared' => true];
            }

            if ($usedOverride !== null) {
                $metadata += ['override' => true, 'override_reason' => $usedOverride];
            }

            if ($missing !== []) {
                $metadata += ['registration_workflow_override' => true, 'registration_steps_missing' => $missing];
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
