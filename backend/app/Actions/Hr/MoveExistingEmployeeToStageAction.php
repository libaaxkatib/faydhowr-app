<?php

namespace App\Actions\Hr;

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Enums\EmployeePipelineStage;
use App\Enums\EmployeeRegistrationOrigin;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Support\ApiResponse;
use App\Support\Hr\EmployeeTransitionGuard;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Moves ONE existing registered person (registered before the strict registration workflow) to the stage the owner chooses:
 * any pipeline stage (Damiin, Contract, Uniform, Need Training, Need Practical, Practical Repeat) or the Waiting List. It is the
 * individual management path for the people who were already in the system, so their records are managed by stage without
 * having to replay a registration they already went through.
 *
 *  - Only a Super Admin or an HR Manager (checked here on the server, not only by the route), with a written reason.
 *  - Only for a registration_origin = existing record. A NEW registration is refused (it follows the workflow); so is office staff.
 *  - Never Active (use Waiting, then the normal activation), and not for someone who is Active or Inactive: use separation / rehire.
 *  - Nothing is deleted: guarantor, contract, uniform, training and practical records stay exactly as they are. Only status,
 *    pipeline stage and the waiting clock change.
 *  - Recorded on the employee status history (source "stage_move", with the reason) and in the audit log (actor, time, from and to
 *    status and stage, reason).
 */
class MoveExistingEmployeeToStageAction
{
    public const WAITING = 'waiting';

    /** @return list<string> */
    public static function targets(): array
    {
        return [
            EmployeePipelineStage::DamiinNeeded->value,
            EmployeePipelineStage::ContractPending->value,
            EmployeePipelineStage::UniformPending->value,
            EmployeePipelineStage::NeedTraining->value,
            EmployeePipelineStage::NeedPractical->value,
            EmployeePipelineStage::PracticalRepeat->value,
            self::WAITING,
        ];
    }

    public function handle(Employee $employee, string $target, string $reason, Admin $actor): Employee
    {
        if (! in_array($actor->role, [AdminRole::SuperAdmin, AdminRole::HrManager], true)) {
            $this->fail('Only a Super Admin or an HR Manager can move a registered person to another stage.', 'STAGE_MOVE_NOT_ALLOWED', 403);
        }

        if (! in_array($target, self::targets(), true)) {
            $this->fail('That stage is not available for a stage move.', 'STAGE_NOT_AVAILABLE');
        }

        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');

        if (mb_strlen($reason) < EmployeeTransitionGuard::MIN_REASON_LENGTH) {
            $this->fail('A reason of at least '.EmployeeTransitionGuard::MIN_REASON_LENGTH.' characters is required to move a person to another stage. Nothing was changed.', 'STAGE_MOVE_REASON_REQUIRED');
        }

        if (mb_strlen($reason) > EmployeeTransitionGuard::MAX_REASON_LENGTH) {
            $this->fail('The reason may not be longer than '.EmployeeTransitionGuard::MAX_REASON_LENGTH.' characters.', 'STAGE_MOVE_REASON_TOO_LONG');
        }

        return DB::transaction(function () use ($employee, $target, $reason, $actor): Employee {
            $locked = Employee::withTrashed()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            if ($locked->trashed()) {
                $this->fail('This employee record is archived and cannot be moved.', 'EMPLOYEE_ARCHIVED');
            }

            if ($locked->registration_origin !== EmployeeRegistrationOrigin::Existing) {
                $this->fail(
                    $locked->registration_origin === EmployeeRegistrationOrigin::New
                        ? "{$locked->full_name} is a new registration and must complete the registration workflow step by step (guarantor, contract, uniform, training, practice); the stage cannot be chosen."
                        : "{$locked->full_name} is office staff; the recruitment stages do not apply.",
                    'NOT_AN_EXISTING_RECORD',
                );
            }

            if (in_array($locked->status, [EmployeeStatus::Active, EmployeeStatus::Inactive], true)) {
                $this->fail(
                    "{$locked->full_name} is {$locked->status->label()}. A stage move is for people who are not yet working; use separation or rehire to change an Active or Inactive employee.",
                    'STAGE_MOVE_NOT_FOR_ACTIVE_OR_INACTIVE',
                );
            }

            $fromStatus = $locked->status;
            $fromStage = $locked->pipeline_stage;
            $toStatus = $target === self::WAITING ? EmployeeStatus::Waiting : EmployeeStatus::Applicant;
            $toStage = $target === self::WAITING ? null : EmployeePipelineStage::from($target);

            if ($fromStatus === $toStatus && $fromStage === $toStage) {
                $this->fail('The person is already in that stage. Nothing was changed.', 'NO_STAGE_CHANGE');
            }

            $locked->update([
                'status' => $toStatus,
                'pipeline_stage' => $toStage,
                'waiting_since' => $toStatus === EmployeeStatus::Waiting
                    ? ($fromStatus === EmployeeStatus::Waiting ? $locked->waiting_since : now())
                    : null,
            ]);

            $fromLabel = $this->label($fromStatus, $fromStage);
            $toLabel = $this->label($toStatus, $toStage);

            EmployeeStatusHistory::query()->create([
                'employee_id' => $locked->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'changed_by' => $actor->id,
                'note' => "Stage moved: {$fromLabel} to {$toLabel}. Reason: {$reason}",
                'source' => 'stage_move',
            ]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Employee '{$locked->full_name}' ({$locked->employee_number}) moved from {$fromLabel} to {$toLabel}.",
                entityType: Employee::class,
                entityId: $locked->id,
                metadata: [
                    'stage_move' => true,
                    'registration_origin' => EmployeeRegistrationOrigin::Existing->value,
                    'from_status' => $fromStatus->value,
                    'to_status' => $toStatus->value,
                    'from_pipeline_stage' => $fromStage?->value,
                    'to_pipeline_stage' => $toStage?->value,
                    'reason' => $reason,
                ],
            ));

            return $locked->load(['category', 'department', 'position', 'statusHistories.changedBy']);
        });
    }

    private function label(EmployeeStatus $status, ?EmployeePipelineStage $stage): string
    {
        return $stage !== null ? $stage->label() : $status->label();
    }

    private function fail(string $message, string $code, int $status = 422): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, $status));
    }
}
