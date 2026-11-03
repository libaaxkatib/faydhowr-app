<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Enums\TemporaryReplacementStatus;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use App\Support\ApiResponse;
use App\Support\Hr\CoverConflicts;
use App\Support\Hr\EmployeeRegistrationWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 3: a replacement covers an EXISTING
 * work assignment - the original employee's assignment is never touched or
 * ended, this just records who is standing in. "Multi-company replacement
 * work" needs no special handling: the replacement is picked from the full
 * employee list, independent of which company they normally work for.
 *
 * Who may be chosen (owner decision 2026-11-03): an Active, Waiting or Applicant Temporary employee. Choosing changes nothing. Only when the cover is
 * SAVED does a Waiting / Applicant person become Active - exactly that person, through the normal status workflow (history + audit), or, when that
 * workflow refuses (Applicant is not one step from Active; a new registration has not finished the registration steps), as a RECORDED EXCEPTION (status
 * history source temporary_cover_exception with the reason and what was missing, an audit event, listed by the HR compliance report). The activation and
 * the cover are one transaction: when the cover is refused or fails, the person's status, history and audit are exactly as they were.
 */
class CreateTemporaryReplacementAction
{
    public function handle(EmployeeWorkAssignment $workAssignment, Employee $replacementEmployee, array $data, Admin $actor): TemporaryReplacement
    {
        if ($workAssignment->status !== WorkAssignmentStatus::Active) {
            throw new HttpResponseException(
                ApiResponse::error(
                    'This work assignment is not currently active.',
                    'WORK_ASSIGNMENT_NOT_ACTIVE',
                    422,
                ),
            );
        }

        $this->assertEmployeeCanCover($workAssignment, $replacementEmployee, allowNotYetActive: true);

        return DB::transaction(function () use ($workAssignment, $replacementEmployee, $data, $actor) {
            // Two people saving at the same moment must not both pass the overlap check: the position and the person are locked, in this order, until the cover is saved.
            EmployeeWorkAssignment::query()->whereKey($workAssignment->id)->lockForUpdate()->first();
            $locked = Employee::query()->whereKey($replacementEmployee->id)->lockForUpdate()->firstOrFail();

            // The same rules again on the locked row (the person may have changed since they were read), before anything is written.
            $this->assertEmployeeCanCover($workAssignment, $locked, allowNotYetActive: true);

            // U12 / U1 (docs/HR_PAYROLL_RULES.md): refused when it overlaps another Temporary cover of the position or of the person, or the person's own assignment.
            CoverConflicts::assertFree(
                $workAssignment,
                $locked,
                CarbonImmutable::parse($data['start_date'])->toDateString(),
                empty($data['end_date']) ? null : CarbonImmutable::parse($data['end_date'])->toDateString(),
            );

            // Only now - every rule passed - is a Waiting / Applicant person made Active, inside this same transaction.
            $this->activate($locked, $workAssignment, $actor);

            $replacement = TemporaryReplacement::query()->create([
                ...$data,
                'currency' => $data['currency'] ?? 'USD',
                'work_assignment_id' => $workAssignment->id,
                'replacement_employee_id' => $replacementEmployee->id,
                'status' => TemporaryReplacementStatus::Active,
                'created_by' => $actor->id,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: "Employee '{$replacementEmployee->full_name}' recorded as a temporary replacement for work assignment #{$workAssignment->id}.",
                entityType: TemporaryReplacement::class,
                entityId: $replacement->id,
            ));

            return $replacement->load('workAssignment.employee', 'workAssignment.workLocation.clientCompany', 'workAssignment.clientCompany', 'replacementEmployee', 'createdBy', 'payments.paidBy');
        });
    }

    /**
     * The statuses a person may have when a NEW cover is saved for them: Active, or Waiting / Applicant (who are made Active by the save itself).
     *
     * @var list<EmployeeStatus>
     */
    public const NOT_YET_ACTIVE = [EmployeeStatus::Waiting, EmployeeStatus::Applicant];

    /**
     * The rules every Temporary employee must meet to cover a position (used when a cover is created and when its Temporary employee is corrected).
     *
     * @param  bool  $allowNotYetActive  true only when a NEW cover is being saved: a Waiting / Applicant person is then acceptable, because saving activates them
     *                                   (activate()), and the registration workflow is dealt with there. Correcting an existing cover keeps requiring an Active person.
     */
    public function assertEmployeeCanCover(EmployeeWorkAssignment $workAssignment, Employee $replacementEmployee, bool $allowNotYetActive = false): void
    {
        $notYetActive = $allowNotYetActive && in_array($replacementEmployee->status, self::NOT_YET_ACTIVE, true);

        if ($replacementEmployee->status !== EmployeeStatus::Active && ! $notYetActive) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$replacementEmployee->full_name}' must be Active to cover a replacement.",
                    'EMPLOYEE_NOT_ACTIVE',
                    422,
                ),
            );
        }

        if (! $notYetActive) {
            (new EmployeeRegistrationWorkflow)->requireEligible($replacementEmployee, 'cannot cover a company position', 'EMPLOYEE_REGISTRATION_INCOMPLETE');
        }

        if ((int) $replacementEmployee->id === (int) $workAssignment->employee_id) {
            throw new HttpResponseException(
                ApiResponse::error(
                    'An employee cannot be recorded as their own replacement.',
                    'EMPLOYEE_CANNOT_REPLACE_SELF',
                    422,
                ),
            );
        }

        if ($replacementEmployee->employment_type !== 'temporary') {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$replacementEmployee->full_name}' is not classified as Temporary. Set the employment type to Temporary first.",
                    'REPLACEMENT_EMPLOYEE_NOT_TEMPORARY',
                    422,
                ),
            );
        }

        if ($workAssignment->employee()->value('employment_type') === 'temporary') {
            throw new HttpResponseException(
                ApiResponse::error(
                    'A Temporary employee can only cover a Permanent employee\'s position.',
                    'COVERED_EMPLOYEE_NOT_PERMANENT',
                    422,
                ),
            );
        }
    }

    /**
     * Makes a Waiting / Applicant person Active because they are being recorded as a Temporary replacement. A person who is already Active is left alone.
     * The normal status workflow is tried first; when it refuses, the change is still made - never silently - as a recorded exception.
     */
    private function activate(Employee $locked, EmployeeWorkAssignment $workAssignment, Admin $actor): void
    {
        if ($locked->status === EmployeeStatus::Active) {
            return;
        }

        $note = "Made Active when recorded as the temporary replacement for work assignment #{$workAssignment->id}.";

        try {
            app(UpdateEmployeeStatusAction::class)->handle($locked, EmployeeStatus::Active, $note, $actor);

            return;
        } catch (HttpResponseException) {
            // The normal path refused (not one step from Active, or the registration workflow is incomplete). The activation goes on, recorded below.
        }

        $workflow = new EmployeeRegistrationWorkflow;
        $from = $locked->status;
        $missing = $workflow->appliesTo($locked) ? $workflow->missing($locked) : [];
        $reason = "Made Active by HR when recorded as a temporary replacement for work assignment #{$workAssignment->id} while the person was '{$from->value}'"
            .($missing === [] ? '' : ' and these registration steps were not complete: '.implode(', ', $missing)).'. Recorded for follow-up in the HR compliance report.';
        $stage = $locked->pipeline_stage;

        $locked->update([
            'status' => EmployeeStatus::Active,
            'pipeline_stage' => null,
            'waiting_since' => $from === EmployeeStatus::Waiting ? null : $locked->waiting_since,
        ]);

        EmployeeStatusHistory::query()->create([
            'employee_id' => $locked->id,
            'from_status' => $from,
            'to_status' => EmployeeStatus::Active,
            'changed_by' => $actor->id,
            'note' => trim($note.($stage === null ? '' : " Pipeline stage '{$stage->label()}' cleared because the person is now Active (the stage is kept here as history).")),
            'override_reason' => $reason,
            'source' => EmployeeRegistrationWorkflow::SOURCE_COVER_EXCEPTION,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Employee '{$locked->full_name}' ({$locked->employee_number}) made Active as a temporary replacement while '{$from->value}': recorded for HR compliance follow-up.",
            entityType: Employee::class,
            entityId: $locked->id,
            metadata: [
                'event' => 'temporary_cover_exception',
                'from_status' => $from->value,
                'to_status' => EmployeeStatus::Active->value,
                'registration_steps_missing' => $missing,
                'work_assignment_id' => $workAssignment->id,
                'reason' => $reason,
            ],
        ));
    }
}
