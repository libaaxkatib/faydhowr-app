<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\TemporaryReplacementStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use App\Support\ApiResponse;
use App\Support\Hr\CoverConflicts;
use App\Support\Hr\ReplacementChangeGuard;
use App\Support\Hr\ReplacementChangeImpact;
use App\Support\Hr\ReplacementVersion;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a Temporary cover that was saved wrongly. Only these can change: the start date, the end date (the planned end of a running cover, the last day of
 * an ended one), the Temporary employee, the reason and the notes. The covered Permanent employee, the covered assignment, the status, the approval
 * and every payment record are NEVER changed here (a payment is a separate record; the Temporary employee cannot be swapped once payments or workdays exist).
 *
 * Every correction needs a written reason and keeps the before and after values in the audit log. A change that adds days in months that are still open
 * clears the approval (the cost has to be approved again). A change that adds or removes a day in an approved or finalized payroll month is a correction of
 * closed payroll (ReplacementChangeGuard): Super Admin only, with a reason, recorded as a payroll correction, the closed run never rewritten.
 */
class UpdateTemporaryReplacementAction
{
    public function __construct(
        private readonly ReplacementChangeImpact $impact = new ReplacementChangeImpact,
        private readonly ReplacementChangeGuard $guard = new ReplacementChangeGuard,
        private readonly CreateTemporaryReplacementAction $create = new CreateTemporaryReplacementAction,
    ) {}

    /**
     * @param  array<string, mixed>  $data  start_date, end_date, replacement_employee_id, reason, notes (only the keys that are sent change), change_reason, version
     */
    public function handle(TemporaryReplacement $replacement, array $data, Admin $actor): TemporaryReplacement
    {
        if (($data['version'] ?? null) !== ReplacementVersion::of($replacement)) {
            throw new HttpResponseException(ApiResponse::error('This replacement was changed by someone else. Reload it and try again. Nothing was changed.', 'TEMPORARY_REPLACEMENT_CHANGED', 409));
        }

        $assignment = $replacement->workAssignment()->firstOrFail();

        $start = array_key_exists('start_date', $data) ? CarbonImmutable::parse($data['start_date'])->toDateString() : $replacement->start_date->toDateString();
        $end = array_key_exists('end_date', $data) ? ($data['end_date'] === null ? null : CarbonImmutable::parse($data['end_date'])->toDateString()) : $replacement->end_date?->toDateString();
        $employeeId = (int) ($data['replacement_employee_id'] ?? $replacement->replacement_employee_id);
        $reason = array_key_exists('reason', $data) ? $this->text($data['reason']) : $replacement->reason;
        $notes = array_key_exists('notes', $data) ? $this->text($data['notes']) : $replacement->notes;

        if ($end !== null && $end < $start) {
            throw new HttpResponseException(ApiResponse::error('The end date cannot be before the start date.', 'TEMPORARY_REPLACEMENT_END_BEFORE_START', 422, ['end_date' => ['The end date cannot be before the start date.']]));
        }

        if ($end === null && $replacement->status === TemporaryReplacementStatus::Ended) {
            throw new HttpResponseException(ApiResponse::error('An ended cover needs its last day.', 'ENDED_COVER_NEEDS_END_DATE', 422, ['end_date' => ['An ended cover needs its last day.']]));
        }

        $datesChanged = $start !== $replacement->start_date->toDateString() || $end !== $replacement->end_date?->toDateString();
        $employeeChanged = $employeeId !== (int) $replacement->replacement_employee_id;

        if (! $datesChanged && ! $employeeChanged && $reason === $replacement->reason && $notes === $replacement->notes) {
            throw new HttpResponseException(ApiResponse::error('Nothing was changed: the values are the same as the saved ones.', 'NO_CHANGES', 422));
        }

        $replacementEmployee = $replacement->replacementEmployee()->firstOrFail();

        if ($employeeChanged) {
            if ($replacement->payments()->exists() || $replacement->workdays()->where('status', '!=', 'void')->exists()) {
                throw new HttpResponseException(ApiResponse::error(
                    'The Temporary employee cannot be changed once payments or workdays are recorded for this cover. End it and record a new cover instead. Nothing was changed.',
                    'REPLACEMENT_EMPLOYEE_HAS_RECORDS',
                    422,
                ));
            }

            $replacementEmployee = Employee::query()->findOrFail($employeeId);
            $this->create->assertEmployeeCanCover($assignment, $replacementEmployee);
        }

        $impact = $this->impact->forChange($replacement, ['start_date' => $start, 'end_date' => $end], false);

        return $this->guard->run($actor, $replacement, $impact, $data['change_reason'] ?? null, 'cover_changed', ['old' => $impact['old'], 'new' => $impact['new']], function () use ($replacement, $assignment, $replacementEmployee, $start, $end, $reason, $notes, $employeeChanged, $impact, $actor, $data) {
            return DB::transaction(function () use ($replacement, $assignment, $replacementEmployee, $start, $end, $reason, $notes, $employeeChanged, $impact, $actor, $data) {
                // Two people saving at the same moment must not both pass the overlap check (same locking order as when a cover is created).
                EmployeeWorkAssignment::query()->whereKey($assignment->id)->lockForUpdate()->first();
                Employee::query()->whereKey($replacementEmployee->id)->lockForUpdate()->first();
                $replacement->refresh();

                if (($data['version'] ?? null) !== ReplacementVersion::of($replacement)) {
                    throw new HttpResponseException(ApiResponse::error('This replacement was changed by someone else. Reload it and try again. Nothing was changed.', 'TEMPORARY_REPLACEMENT_CHANGED', 409));
                }

                // U12 / U1: the corrected cover must not overlap another Temporary cover of the position or of the person, nor the person's own assignment.
                CoverConflicts::assertFree($assignment, $replacementEmployee, $start, $end, $replacement->id);

                $before = $this->snapshot($replacement);

                $approvalCleared = false;
                $attributes = ['start_date' => $start, 'end_date' => $end, 'replacement_employee_id' => $replacementEmployee->id, 'reason' => $reason, 'notes' => $notes];

                if ($replacement->approved_at !== null && $impact['days_added'] > 0 && ! $impact['touches_locked_period']) {
                    $attributes += ['approved_at' => null, 'approved_by' => null];
                    $approvalCleared = true;
                }

                $replacement->update($attributes);
                $after = $this->snapshot($replacement->refresh());

                $outsideWorkdays = $replacement->workdays()->where('status', '!=', 'void')->where(fn ($q) => $q->whereDate('work_date', '<', $start)->when($end !== null, fn ($q2) => $q2->orWhereDate('work_date', '>', $end)))->count();

                event(AuditEvent::record(
                    action: AuditAction::Update,
                    admin: $actor,
                    description: "Temporary replacement #{$replacement->id} corrected.",
                    entityType: TemporaryReplacement::class,
                    entityId: $replacement->id,
                    metadata: [
                        'event' => 'temporary_replacement_corrected',
                        'before' => $before,
                        'after' => $after,
                        'reason' => trim((string) $data['change_reason']),
                        'days_added' => $impact['days_added'],
                        'days_removed' => $impact['days_removed'],
                        'approval_cleared' => $approvalCleared,
                        'approval_kept_because_closed_payroll' => $replacement->approved_at !== null && $impact['days_added'] > 0 && $impact['touches_locked_period'],
                        'locked_periods_changed' => $impact['locked_periods_changed'],
                        'employee_changed' => $employeeChanged,
                        'workdays_outside_new_period' => $outsideWorkdays,
                    ],
                ));

                return $replacement->load('workAssignment.employee', 'workAssignment.workLocation.clientCompany', 'workAssignment.clientCompany', 'replacementEmployee', 'createdBy', 'payments.paidBy');
            });
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(TemporaryReplacement $replacement): array
    {
        return [
            'start_date' => $replacement->start_date->toDateString(),
            'end_date' => $replacement->end_date?->toDateString(),
            'replacement_employee_id' => (int) $replacement->replacement_employee_id,
            'replacement_employee' => $replacement->replacementEmployee?->full_name,
            'reason' => $replacement->reason,
            'notes' => $replacement->notes,
            'status' => $replacement->status->value,
            'approved' => $replacement->approved_at !== null,
        ];
    }

    private function text(mixed $value): ?string
    {
        $text = $value === null ? null : trim((string) $value);

        return $text === '' ? null : $text;
    }
}
