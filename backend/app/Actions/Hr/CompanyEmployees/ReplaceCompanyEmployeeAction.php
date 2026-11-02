<?php

namespace App\Actions\Hr\CompanyEmployees;

use App\Actions\Hr\CreateWorkAssignmentAction;
use App\Actions\Hr\EndWorkAssignmentAction;
use App\Enums\AssignmentType;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\AssignmentAbsence;
use App\Models\AssignmentCycleEvent;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Support\Hr\AbsenceCycles;
use App\Support\Hr\CompanyEmployees\CompanyAssignmentGuard;
use App\Support\Hr\Payroll\ClosedPayrollCorrection;
use App\Support\Hr\PositionSuccession;
use Illuminate\Support\Facades\DB;

/**
 * Replace Employee. In ONE transaction (if anything fails, nothing changes) the replacement's assignment starts after the original's last working day
 * and is linked to the original (replaces_assignment_id). Everything paid under either assignment is kept.
 *
 *  - A REPLACEMENT for a Permanent assignment (the person is only away): the original assignment is NOT ended and not copied. It stays open under the
 *    same id; an absence (assignment_absences) and its events record the last working day, the Replacement and - later - the return, so payroll does not
 *    pay the original for the days of the absence and the same row is the canonical record across any number of cycles.
 *  - A PERMANENT successor, or replacing a Replacement: the original assignment ends on its last day, as before.
 *
 * The replacement is explicitly Permanent or Replacement (never inferred). A REPLACEMENT has no salary of its own: its salary basis is
 * the monthly salary of the Permanent employee it replaces (see CreateWorkAssignmentAction). A Permanent successor brings the salary HR
 * enters. A gap between the last working day and the replacement date is allowed and reported (nobody is paid for it); an overlap (a day paid to two
 * people) is refused by the existing succession rule. A change reaching into an approved or finalized payroll month is a Super Admin correction
 * (reason required) - see ClosedPayrollCorrection.
 */
class ReplaceCompanyEmployeeAction
{
    public function __construct(
        private readonly EndWorkAssignmentAction $end,
        private readonly CreateWorkAssignmentAction $create,
        private readonly ClosedPayrollCorrection $corrections = new ClosedPayrollCorrection,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{original: EmployeeWorkAssignment, replacement: EmployeeWorkAssignment, absence?: AssignmentAbsence}
     */
    public function handle(ClientCompany $company, EmployeeWorkAssignment $original, array $data, Admin $actor): array
    {
        CompanyAssignmentGuard::requireOpen($original);

        $replacementDate = CompanyAssignmentGuard::date($data['replacement_date']);
        $lastDay = isset($data['original_last_day']) ? CompanyAssignmentGuard::date($data['original_last_day']) : $replacementDate->subDay();
        $replacement = Employee::withTrashed()->findOrFail((int) $data['replacement_employee_id']);

        if ($replacement->id === $original->employee_id) {
            CompanyAssignmentGuard::fail('An employee cannot replace themselves. Choose a different employee.', 'REPLACEMENT_SAME_EMPLOYEE');
        }

        CompanyAssignmentGuard::requireAssignable($replacement);

        if ($lastDay->lt(CompanyAssignmentGuard::date($original->start_date->toDateString()))) {
            CompanyAssignmentGuard::fail(
                "The original employee's last working day ({$lastDay->toDateString()}) is before the assignment started ({$original->start_date->toDateString()}). Choose a last working day on or after the start date.",
                'REPLACEMENT_BEFORE_ASSIGNMENT_START',
                ['original_last_day' => ['The last working day cannot be before the assignment started on '.$original->start_date->toDateString().'.']],
            );
        }

        PositionSuccession::assertNoOverlap($lastDay, $replacementDate);

        $original->loadMissing('employee', 'position');

        // The holder is only away (a Replacement for a Permanent assignment): the assignment stays open and keeps its id.
        $cover = $data['assignment_type'] === AssignmentType::Replacement->value && ($original->assignment_type ?? AssignmentType::Permanent) === AssignmentType::Permanent;

        // The row being replaced is itself a Replacement covering an away holder: handing the cover to another Replacement is a SWAP inside the same
        // absence (the holder's return then still works). Anything else would strand the absence, so it is refused with the way out.
        $covered = AbsenceCycles::boundAbsence($original);
        $swap = $covered !== null && $data['assignment_type'] === AssignmentType::Replacement->value;

        if ($covered !== null && ! $swap) {
            AbsenceCycles::assertNotBound($original, 'Replacing this Replacement with a Permanent employee');
        }

        if (! $cover && ! $swap && ($original->assignment_type ?? AssignmentType::Permanent) === AssignmentType::Permanent && AbsenceCycles::open($original)?->terminated_on === null && AbsenceCycles::open($original) !== null) {
            CompanyAssignmentGuard::fail('This employee is away and a Replacement covers the position. To give the position to someone else permanently, first end this assignment with Stop Employee (the Replacement keeps working), then make the Replacement Permanent or replace them.', 'ORIGINAL_IS_AWAY');
        }

        return $this->corrections->run($actor, 'replaced', [$original->employee_id, $replacement->id], $lastDay->addDay()->toDateString(), $data['correction_reason'] ?? null, [
            'assignment_id' => $original->id,
            'old' => ['assignment_id' => $original->id, 'employee_id' => $original->employee_id, 'end_date' => $original->end_date?->toDateString()],
            'new' => ['original_last_day' => $lastDay->toDateString(), 'replacement_employee_id' => $replacement->id, 'replacement_start_date' => $replacementDate->toDateString(), 'assignment_type' => $data['assignment_type']],
        ], fn (): array => DB::transaction(function () use ($company, $original, $data, $actor, $replacementDate, $lastDay, $replacement, $cover, $swap): array {
            $reason = trim((string) ($data['reason'] ?? ''));
            $absence = null;
            $holderAssignmentId = $original->id;

            if ($cover) {
                // Locks the row first so two people cannot open two absences at once; refuses an open or overlapping cycle.
                EmployeeWorkAssignment::query()->lockForUpdate()->findOrFail($original->id);
                $absence = AbsenceCycles::start($original, $lastDay, $actor);
            } elseif ($swap) {
                $locked = EmployeeWorkAssignment::query()->lockForUpdate()->findOrFail($original->id);
                $absence = AbsenceCycles::boundAbsence($locked);

                if ($absence === null) {
                    CompanyAssignmentGuard::fail('This Replacement is no longer covering an absence (the employee may just have returned). Reload and check the position before changing it.', 'REPLACEMENT_NO_LONGER_COVERS');
                }

                $holderAssignmentId = $absence->work_assignment_id;
                $this->end->handle($original, [
                    'end_date' => $lastDay->toDateString(),
                    'note' => "Replaced by {$replacement->full_name} ({$replacement->employee_number}) from {$replacementDate->toDateString()}.".($reason !== '' ? " Reason: {$reason}" : ''),
                    'succession_handled' => true,
                    'cycle_managed' => true,
                ], $actor);
            } else {
                $this->end->handle($original, [
                    'end_date' => $lastDay->toDateString(),
                    'note' => "Replaced by {$replacement->full_name} ({$replacement->employee_number}) from {$replacementDate->toDateString()}.".($reason !== '' ? " Reason: {$reason}" : ''),
                    // Replace Employee names the successor itself, in this same transaction.
                    'succession_handled' => true,
                ], $actor);
            }

            $new = $this->create->handle($replacement, [
                'client_company_id' => $company->id,
                'work_location_id' => $original->work_location_id,
                // A swap keeps pointing at the holder who is away (so Return finds them), not at the Replacement being swapped out.
                'replaces_assignment_id' => $holderAssignmentId,
                'cycle_managed' => $cover || $swap,
                'position_id' => $data['position_id'] ?? $original->position_id,
                'assignment_type' => $data['assignment_type'],
                // The replacement takes over the position, so the shift of the position carries over unless HR chooses another one.
                'shift' => array_key_exists('shift', $data) ? $data['shift'] : $original->shift?->value,
                'start_date' => $replacementDate->toDateString(),
                // A Replacement's salary is inherited from the replaced Permanent (CreateWorkAssignmentAction ignores/validates these);
                // a Permanent successor brings the salary HR entered.
                ...($data['assignment_type'] === AssignmentType::Replacement->value ? array_intersect_key($data, array_flip(['salary_amount', 'salary_currency'])) : [
                    'salary_amount' => $data['salary_amount'],
                    'salary_currency' => strtoupper((string) $data['salary_currency']),
                    'salary_frequency' => $data['salary_frequency'],
                ]),
                'acknowledge_temporary_as_replacement' => ($data['acknowledge_temporary_as_replacement'] ?? false) === true,
                'notes' => $data['notes'] ?? null,
            ], $actor);

            $gap = PositionSuccession::gapDays($lastDay, $replacementDate);

            if ($absence !== null) {
                if ($swap) {
                    AbsenceCycles::event($absence, AssignmentCycleEvent::REPLACEMENT_ENDED, $lastDay, $actor, $original->id, ['swapped_for_assignment_id' => $new->id]);
                }

                AbsenceCycles::event($absence, AssignmentCycleEvent::REPLACEMENT_STARTED, $replacementDate, $actor, $new->id, [
                    'replacement_employee_id' => $replacement->id,
                    'gap_days' => $gap,
                    'reason' => $reason !== '' ? $reason : null,
                    ...($swap ? ['takes_over_from_replacement_assignment_id' => $original->id] : []),
                ]);
            }

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "'{$original->employee?->full_name}' ({$original->employee?->employee_number}) replaced by '{$replacement->full_name}' ({$replacement->employee_number}) at '{$company->name}'.",
                entityType: EmployeeWorkAssignment::class,
                entityId: $original->id,
                metadata: [
                    'event' => 'replaced',
                    'company_id' => $company->id,
                    'position_id' => $original->position_id,
                    'original_assignment_id' => $original->id,
                    'original_employee_id' => $original->employee_id,
                    'original_last_day' => $lastDay->toDateString(),
                    'replacement_assignment_id' => $new->id,
                    'replacement_employee_id' => $replacement->id,
                    'replacement_start_date' => $replacementDate->toDateString(),
                    'assignment_type' => $new->assignment_type->value,
                    'shift' => $new->shift?->value,
                    'gap_days' => $gap,
                    'original_stays_open' => $cover,
                    'cover_swap' => $swap,
                    'holder_assignment_id' => $swap ? $holderAssignmentId : null,
                    'absence_id' => $absence?->id,
                    'temporary_registered_as_replacement_confirmed' => $replacement->employment_type === 'temporary' && $new->assignment_type === AssignmentType::Replacement,
                    'reason' => $reason !== '' ? $reason : null,
                ],
            ));

            return [
                'original' => $original->refresh()->load(['employee', 'position']),
                'replacement' => $new->load(['employee', 'position']),
                ...($absence === null ? [] : ['absence' => $absence]),
            ];
        }));
    }
}
