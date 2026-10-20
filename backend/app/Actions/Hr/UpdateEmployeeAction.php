<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Support\ApiResponse;
use App\Support\Hr\EmployeeDuplicateFinder;
use App\Support\Hr\UnresolvedTemporaryReplacements;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;

/**
 * Edits an employee. Setting a phone / alternate phone that already belongs to
 * a DIFFERENT person (archived records included) is refused unless the caller
 * confirms it with a reason, so an edit cannot quietly create a duplicate
 * identity; the override is recorded in the audit trail.
 */
class UpdateEmployeeAction
{
    public function __construct(private readonly EmployeeDuplicateFinder $duplicates) {}

    public function handle(Employee $employee, array $data, Admin $actor): Employee
    {
        $confirmed = (bool) ($data['confirm_duplicate'] ?? false);
        $reason = $data['duplicate_reason'] ?? null;
        $attributes = Arr::except($data, ['confirm_duplicate', 'duplicate_reason']);

        $matches = collect();

        if (array_key_exists('phone', $attributes) || array_key_exists('alternate_phone', $attributes)) {
            $matches = $this->duplicates->find([
                'phone' => $attributes['phone'] ?? null,
                'alternate_phone' => $attributes['alternate_phone'] ?? null,
            ], $employee->id);
        }

        if ($matches->isNotEmpty() && ! $confirmed) {
            throw new HttpResponseException(ApiResponse::error(
                'Another employee already uses this phone number. Confirm it is intentional with a reason, or correct the number.',
                'DUPLICATE_EMPLOYEE_PHONE',
                422,
                ['matches' => $matches->all()],
            ));
        }

        $oldType = $employee->employment_type;
        // Replacements of this employee that are unresolved (a Temporary worker confirmed as a Replacement) BEFORE this edit.
        $unresolvedBefore = $oldType === 'temporary' && array_key_exists('employment_type', $attributes)
            ? UnresolvedTemporaryReplacements::query()->where('employee_id', $employee->id)->get()
            : collect();

        $employee->update($attributes);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Employee '{$employee->full_name}' ({$employee->employee_number}) updated.",
            entityType: Employee::class,
            entityId: $employee->id,
            metadata: [
                ...($matches->isEmpty() ? [] : [
                    'duplicate_override' => true,
                    'reason' => $reason,
                    'matched_employee_ids' => $matches->pluck('id')->all(),
                ]),
                ...($oldType !== $employee->employment_type ? ['employment_type' => ['from' => $oldType, 'to' => $employee->employment_type]] : []),
            ] ?: null,
        ));

        // The correction of a Temporary worker who was confirmed as a Replacement: recorded on each assignment it resolves (who, when, how).
        foreach ($unresolvedBefore as $assignment) {
            if (! UnresolvedTemporaryReplacements::isUnresolved($assignment->refresh())) {
                UnresolvedTemporaryReplacements::auditResolved($assignment, 'employee_type_changed', $actor, ['employment_type_from' => $oldType, 'employment_type_to' => $employee->employment_type]);
            }
        }

        return $employee->load(['category', 'department', 'position']);
    }
}
