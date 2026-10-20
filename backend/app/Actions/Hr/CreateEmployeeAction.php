<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeePipelineStage;
use App\Enums\EmployeeRegistrationOrigin;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Support\ApiResponse;
use App\Support\Hr\EmployeeCodeGenerator;
use App\Support\Hr\EmployeeDuplicateFinder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Registers a NEW applicant. Before creating anything it looks for an existing
 * person (same phone / alternate phone on the last nine digits, or an
 * identical full name), archived records included. A shared PHONE is refused
 * unless the caller explicitly confirms it is a different person and gives a
 * reason; the override and the matched records are written to the audit
 * trail. An identical name alone does not block (different people can share a
 * name) but is recorded for review. Registering the same person twice is
 * therefore a deliberate, recorded act, never an accident.
 */
class CreateEmployeeAction
{
    public function __construct(
        private EmployeeCodeGenerator $codeGenerator,
        private EmployeeDuplicateFinder $duplicates,
    ) {}

    public function handle(array $data, Admin $actor): Employee
    {
        $confirmed = (bool) ($data['confirm_duplicate'] ?? false);
        $reason = $data['duplicate_reason'] ?? null;
        $attributes = Arr::except($data, ['confirm_duplicate', 'duplicate_reason']);

        $matches = $this->duplicates->find($attributes);
        $phoneMatches = $matches->filter(fn (array $m): bool => in_array(EmployeeDuplicateFinder::MATCH_PHONE, $m['matched_on'], true));

        // Only a shared phone number blocks: two different people can share a full
        // name, so an identical name alone is recorded for review, not refused.
        if ($phoneMatches->isNotEmpty() && ! $confirmed) {
            throw new HttpResponseException(ApiResponse::error(
                'An employee with this phone number already exists. Open the existing record, or confirm this is a different person with a reason.',
                'DUPLICATE_EMPLOYEE_PHONE',
                422,
                ['matches' => $phoneMatches->values()->all()],
            ));
        }

        return DB::transaction(function () use ($attributes, $actor, $matches, $phoneMatches, $reason) {
            $employee = Employee::query()->create([
                ...$attributes,
                'employee_number' => $this->codeGenerator->next(),
                'status' => EmployeeStatus::Applicant,
                'registration_origin' => EmployeeRegistrationOrigin::New,
                'pipeline_stage' => EmployeePipelineStage::DamiinNeeded,
                'created_by' => $actor->id,
            ]);

            EmployeeStatusHistory::query()->create([
                'employee_id' => $employee->id,
                'from_status' => null,
                'to_status' => EmployeeStatus::Applicant,
                'changed_by' => $actor->id,
                'note' => 'Registration created.',
            ]);

            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: "Employee '{$employee->full_name}' registered ({$employee->employee_number}).",
                entityType: Employee::class,
                entityId: $employee->id,
                metadata: $matches->isEmpty() ? null : [
                    'duplicate_override' => $phoneMatches->isNotEmpty(),
                    'reason' => $reason,
                    'matched_employee_ids' => $matches->pluck('id')->all(),
                    'possible_same_name_employee_ids' => $matches->diffKeys($phoneMatches)->pluck('id')->values()->all(),
                ],
            ));

            return $employee->load(['category', 'department', 'position']);
        });
    }
}
