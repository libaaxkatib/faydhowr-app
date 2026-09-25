<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeSeparationReason;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeSeparation;
use App\Models\EmployeeStatusHistory;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 3 (Workforce Operations): replaces the
 * bare status-flip the "Mark Inactive" button used to perform - every
 * transition to Inactive now captures a reason, mirroring UpdateEmployeeStatusAction's
 * status-history + waiting_since-clearing behavior but adding the structured
 * separation record. UpdateEmployeeStatusAction itself is untouched and still
 * drives every other (forward) transition.
 */
class MarkEmployeeSeparatedAction
{
    public function handle(
        Employee $employee,
        EmployeeSeparationReason $reason,
        string $separationDate,
        bool $rehireEligible,
        ?string $notes,
        Admin $actor,
    ): Employee {
        if ($employee->status === EmployeeStatus::Inactive) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$employee->full_name}' is already inactive.",
                    'EMPLOYEE_ALREADY_INACTIVE',
                    422,
                ),
            );
        }

        return DB::transaction(function () use ($employee, $reason, $separationDate, $rehireEligible, $notes, $actor) {
            $from = $employee->status;

            $employee->update([
                'status' => EmployeeStatus::Inactive,
                'waiting_since' => $from === EmployeeStatus::Waiting ? null : $employee->waiting_since,
            ]);

            EmployeeStatusHistory::query()->create([
                'employee_id' => $employee->id,
                'from_status' => $from,
                'to_status' => EmployeeStatus::Inactive,
                'changed_by' => $actor->id,
                'note' => "Separated ({$reason->label()}).",
            ]);

            EmployeeSeparation::query()->create([
                'employee_id' => $employee->id,
                'reason' => $reason,
                'separation_date' => $separationDate,
                'rehire_eligible' => $rehireEligible,
                'notes' => $notes,
                'separated_by' => $actor->id,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Employee '{$employee->full_name}' separated ({$reason->label()}).",
                entityType: Employee::class,
                entityId: $employee->id,
                metadata: ['reason' => $reason->value, 'separation_date' => $separationDate],
            ));

            return $employee->load('category', 'department', 'position', 'statusHistories.changedBy', 'separations.separatedBy', 'latestSeparation.separatedBy');
        });
    }
}
