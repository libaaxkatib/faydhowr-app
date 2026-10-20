<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Support\Hr\EmployeeRegistrationWorkflow;
use App\Support\Hr\EmployeeTransitionGuard;
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
        private readonly EmployeeTransitionGuard $guard,
        private readonly EmployeeRegistrationWorkflow $workflow = new EmployeeRegistrationWorkflow,
    ) {}

    /**
     * @param  list<array{employee_id: int, source_ref?: ?string, expected_employee_number?: ?string, expected_status?: ?string}>  $records
     * @return array{dry_run: bool, summary: array<string, int>, results: list<array<string, mixed>>}
     */
    public function handle(array $records, string $note, ?string $joiningDate, bool $dryRun, Admin $actor, ?string $overrideReason = null): array
    {
        $results = [];

        foreach ($records as $record) {
            $results[] = $this->one($record, $note, $joiningDate, $dryRun, $actor, $overrideReason);
        }

        $summary = ['total' => count($results), 'activated' => 0, 'would_activate' => 0, 'would_activate_with_override' => 0, 'requires_override' => 0, 'already_active' => 0, 'failed' => 0];

        foreach ($results as $result) {
            $summary[match ($result['outcome']) {
                'activated' => 'activated',
                'would_activate' => 'would_activate',
                'would_activate_with_override' => 'would_activate_with_override',
                'requires_override' => 'requires_override',
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
    private function one(array $record, string $note, ?string $joiningDate, bool $dryRun, Admin $actor, ?string $overrideReason): array
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

        // Inactive employees return through Rehire (a normal step); everyone else is checked by the
        // lifecycle guard. A change that is not a normal step needs a Super Admin override reason.
        // A new registration that has not completed the registration workflow is held back too (only a Super Admin override, with a
        // reason, gets it through; a separated one has no override path here).
        $workflowBlocked = $this->workflow->blocks($employee);

        if ($workflowBlocked && $employee->status === EmployeeStatus::Inactive) {
            return [...$base, 'to_status' => 'active', 'outcome' => 'failed', 'code' => 'REGISTRATION_WORKFLOW_INCOMPLETE', 'message' => 'New registration that has not completed the registration workflow; still missing: '.implode(', ', $this->workflow->missing($employee)).'.'];
        }

        $needsOverride = $workflowBlocked || ($employee->status !== EmployeeStatus::Inactive
            && $this->guard->evaluate($employee->status, EmployeeStatus::Active) === EmployeeTransitionGuard::DECISION_OVERRIDE_REQUIRED);
        $hasReason = $overrideReason !== null && trim($overrideReason) !== '';

        if ($needsOverride && ! $hasReason) {
            if ($workflowBlocked) {
                return [...$base, 'to_status' => 'active', 'outcome' => $dryRun ? 'requires_override' : 'failed', 'code' => 'REGISTRATION_WORKFLOW_INCOMPLETE', 'message' => 'New registration that has not completed the registration workflow; still missing: '.implode(', ', $this->workflow->missing($employee)).'. Only a Super Admin override with a reason can activate them.'];
            }

            return [...$base, 'to_status' => 'active', 'outcome' => $dryRun ? 'requires_override' : 'failed', 'code' => 'TRANSITION_OVERRIDE_REQUIRED', 'message' => "{$employee->status->label()} to Active is not a normal lifecycle step; it needs a Super Admin override with a reason."];
        }

        if ($dryRun) {
            if ($needsOverride) {
                return [...$base, 'to_status' => 'active', 'outcome' => 'would_activate_with_override', 'code' => null, 'message' => 'Would be moved to Active by Super Admin override (reason recorded in the status history).'];
            }

            return [...$base, 'to_status' => 'active', 'outcome' => 'would_activate', 'code' => null, 'message' => $employee->status === EmployeeStatus::Inactive
                ? 'Would be reactivated through Rehire (separation history kept).'
                : 'Would be moved to Active (status history kept).'];
        }

        $statusNote = $note.($base['source_ref'] ? " [{$base['source_ref']}]" : '');

        try {
            DB::transaction(function () use ($employee, $statusNote, $joiningDate, $actor, $needsOverride, $overrideReason): void {
                if ($employee->status === EmployeeStatus::Inactive) {
                    $this->rehireAction->handle($employee, $statusNote, $actor);
                } else {
                    $this->statusAction->handle($employee, EmployeeStatus::Active, $statusNote, $actor, $needsOverride ? $overrideReason : null);
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
