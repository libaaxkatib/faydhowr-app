<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Support\ApiResponse;
use App\Support\Hr\EmployeeDuplicateFinder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Brings an archived employee back into the working lists. It is an explicit action - editing an archived person never does it - and it
 * changes exactly one thing: the archive mark. The person keeps their id, employee number, status and pipeline stage (every archived person
 * already sits in a valid state of the existing lifecycle - waiting, an applicant stage, or former - and is returned to that same state, not to a
 * new one), and every document, history entry, assignment and other relationship stays as it was.
 *
 * Refused (422, nothing written, nothing audited): a person who is not archived (a second restore, or one that never was archived), and a phone
 * number that a CURRENT employee already uses - the same reasoned confirmation as when an employee is edited or registered. Another archived
 * record with the same number does not block it. One audit record is written, at the end, only when the restore really happened.
 */
class RestoreEmployeeAction
{
    public function __construct(private readonly EmployeeDuplicateFinder $duplicates) {}

    /**
     * @param  array{reason?: ?string, confirm_duplicate?: bool, duplicate_reason?: ?string}  $data
     */
    public function handle(Employee $employee, array $data, Admin $actor): Employee
    {
        $reason = isset($data['reason']) ? trim((string) $data['reason']) : null;
        $reason = $reason === '' ? null : $reason;
        $confirmed = (bool) ($data['confirm_duplicate'] ?? false);
        $duplicateReason = $data['duplicate_reason'] ?? null;

        return DB::transaction(function () use ($employee, $reason, $confirmed, $duplicateReason, $actor): Employee {
            // Locked and re-read inside the transaction: two people pressing Restore together restore once, the second is told it is done.
            $locked = Employee::withTrashed()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            if (! $locked->trashed()) {
                throw new HttpResponseException(ApiResponse::error(
                    'This employee is not archived, so there is nothing to restore.',
                    'EMPLOYEE_NOT_ARCHIVED',
                    422,
                ));
            }

            $matches = $this->duplicates
                ->find(['phone' => $locked->phone, 'alternate_phone' => $locked->alternate_phone], $locked->id)
                ->filter(fn (array $match): bool => ! $match['archived'] && in_array(EmployeeDuplicateFinder::MATCH_PHONE, $match['matched_on'], true))
                ->values();

            if ($matches->isNotEmpty() && ! $confirmed) {
                throw new HttpResponseException(ApiResponse::error(
                    'A current employee already uses this phone number. Confirm that this is a different person, with a reason, or correct the number first.',
                    'DUPLICATE_EMPLOYEE_PHONE',
                    422,
                    ['matches' => $matches->all()],
                ));
            }

            $archivedAt = $locked->deleted_at;
            $locked->restore();

            $lifecycle = ['status' => $locked->status->value, 'pipeline_stage' => $locked->pipeline_stage?->value];

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Employee '{$locked->full_name}' ({$locked->employee_number}) restored from the archive.",
                entityType: Employee::class,
                entityId: $locked->id,
                metadata: [
                    'restored_from_archive' => true,
                    'archived_at' => $archivedAt?->toIso8601String(),
                    'before' => ['archived' => true, ...$lifecycle],
                    'after' => ['archived' => false, ...$lifecycle],
                    ...($reason === null ? [] : ['reason' => $reason]),
                    ...($matches->isEmpty() ? [] : [
                        'duplicate_override' => true,
                        'duplicate_reason' => $duplicateReason,
                        'matched_employee_ids' => $matches->pluck('id')->all(),
                    ]),
                ],
            ));

            return $locked->load(['category', 'department', 'position']);
        });
    }
}
