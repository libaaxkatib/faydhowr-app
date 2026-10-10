<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Moves an explicit list of existing employees to Active, for people the
 * business has approved as working (for example the approved company
 * workbook). Every employee is validated and applied SEPARATELY, in its own
 * transaction, through the normal status workflow, so each gets its own status
 * history row and audit event and one failure never blocks or rolls back the
 * others. Existing history is never edited: an Inactive employee is
 * reactivated through the existing Rehire action (separation rows stay), and
 * no training/practical/uniform completion is ever invented. Defaults to a
 * dry run that changes nothing.
 *
 * Each record: employee_id (required), plus optional source_ref,
 * expected_employee_number and expected_status as safety checks - if the
 * database no longer matches what was approved, that employee is skipped and
 * reported rather than changed.
 */
class BulkActivateEmployeesAction
{
    public const MAX_RECORDS = 300;

    public function __construct(
        private readonly UpdateEmployeeStatusAction $statusAction,
        private readonly RehireEmployeeAction $rehireAction,
    ) {}

    /**
     * @param  list<array{employee_id: int, source_ref?: ?string, expected_employee_number?: ?string, expected_status?: ?string}>  $records
     * @return array{dry_run: bool, summary: array<string, int>, results: list<array<string, mixed>>}
     */
    public function handle(array $records, string $note, ?string $joiningDate, bool $dryRun, Admin $actor): array
    {
        $results = [];

        foreach ($records as $record) {
            $results[] = $this->one($record, $note, $joiningDate, $dryRun, $actor);
        }

        $summary = ['total' => count($results), 'activated' => 0, 'would_activate' => 0, 'already_active' => 0, 'failed' => 0];

        foreach ($results as $result) {
            $summary[match ($result['outcome']) {
                'activated' => 'activated',
                'would_activate' => 'would_activate',
                'already_active' => 'already_active',
                default => 'failed',
            }]++;
        }

        return ['dry_run' => $dryRun, 'summary' => $summary, 'results' => $results];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function one(array $record, string $note, ?string $joiningDate, bool $dryRun, Admin $actor): array
    {
        $base = ['employee_id' => $record['employee_id'], 'source_ref' => $record['source_ref'] ?? null];
        $employee = Employee::withTrashed()->find($record['employee_id']);

        if ($employee === null) {
            return [...$base, 'outcome' => 'failed', 'code' => 'EMPLOYEE_NOT_FOUND', 'message' => 'No such employee.'];
        }

        $base['employee_number'] = $employee->employee_number;
        $base['from_status'] = $employee->status->value;

        if ($employee->trashed()) {
            return [...$base, 'outcome' => 'failed', 'code' => 'EMPLOYEE_ARCHIVED', 'message' => 'Archived employees are not changed.'];
        }

        if (! empty($record['expected_employee_number']) && $record['expected_employee_number'] !== $employee->employee_number) {
            return [...$base, 'outcome' => 'failed', 'code' => 'IDENTITY_MISMATCH', 'message' => "Expected {$record['expected_employee_number']} but found {$employee->employee_number}."];
        }

        // Already Active (for example this is a safe re-run) is the desired end state:
        // nothing to change, so the "expected status" safety check does not apply.
        if ($employee->status === EmployeeStatus::Active) {
            return [...$base, 'to_status' => 'active', 'outcome' => 'already_active', 'code' => null, 'message' => 'Already Active; nothing changed.'];
        }

        if (! empty($record['expected_status']) && $record['expected_status'] !== $employee->status->value) {
            return [...$base, 'outcome' => 'failed', 'code' => 'STATUS_CHANGED_SINCE_PLAN', 'message' => "Expected status {$record['expected_status']} but it is {$employee->status->value}."];
        }

        if ($employee->status === EmployeeStatus::Active) {
            return [...$base, 'to_status' => 'active', 'outcome' => 'already_active', 'code' => null, 'message' => 'Already Active; nothing changed.'];
        }

        if ($dryRun) {
            return [...$base, 'to_status' => 'active', 'outcome' => 'would_activate', 'code' => null, 'message' => $employee->status === EmployeeStatus::Inactive
                ? 'Would be reactivated through Rehire (separation history kept).'
                : 'Would be moved to Active (status history kept).'];
        }

        $statusNote = $note.($base['source_ref'] ? " [{$base['source_ref']}]" : '');

        try {
            DB::transaction(function () use ($employee, $statusNote, $joiningDate, $actor): void {
                if ($employee->status === EmployeeStatus::Inactive) {
                    $this->rehireAction->handle($employee, $statusNote, $actor);
                } else {
                    $this->statusAction->handle($employee, EmployeeStatus::Active, $statusNote, $actor);
                }

                if ($joiningDate !== null && $employee->fresh()->joining_date === null) {
                    $employee->update(['joining_date' => CarbonImmutable::parse($joiningDate)->toDateString()]);
                    event(AuditEvent::record(
                        action: AuditAction::Update,
                        admin: $actor,
                        description: "Joining date set to {$joiningDate} for '{$employee->full_name}' ({$employee->employee_number}).",
                        entityType: Employee::class,
                        entityId: $employee->id,
                    ));
                }
            });
        } catch (HttpResponseException $e) {
            return [...$base, 'outcome' => 'failed', 'code' => $e->getResponse()->getData(true)['error_code'] ?? 'FAILED', 'message' => $e->getResponse()->getData(true)['message'] ?? 'Failed.'];
        } catch (\Throwable $e) {
            return [...$base, 'outcome' => 'failed', 'code' => 'UNEXPECTED_ERROR', 'message' => $e->getMessage()];
        }

        return [...$base, 'to_status' => 'active', 'outcome' => 'activated', 'code' => null, 'message' => 'Moved to Active.'];
    }
}
