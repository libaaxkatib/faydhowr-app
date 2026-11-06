<?php

namespace App\Support\Hr\Compliance;

use App\Enums\AssignmentType;
use App\Enums\EmployeeStatus;
use App\Enums\WorkAssignmentStatus;
use App\Models\AssignmentAbsence;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeWorkAssignment;
use App\Models\HrComplianceFinding;
use App\Models\TemporaryReplacement;
use App\Support\Hr\EmployeeRegistrationWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finds the people who work, or are assigned to work, while a requirement the system ALREADY defines is not met. It invents no rule: every check below is
 * an existing validation, mandatory field or approved HR rule (docs/HR_PAYROLL_RULES.md, the registration workflow, the assignment request rules). It
 * reads only; it never changes an employee, an assignment, a payroll line or a status (the findings are stored separately, see ComplianceReport).
 *
 * Findings are classified:
 *   confirmed     a defined rule is demonstrably not met;
 *   needs_review  a requirement is not defined as mandatory, or cannot be verified from the records, so HR must look (never reported as a violation).
 *
 * "Who is responsible" is stated ONLY when a record says so (the creator of the assignment or cover, the person who changed the employee's status, the
 * person who registered the employee). When no record names anyone the finding carries no responsible person - it is never guessed.
 *
 * One finding per underlying issue: the key is rule + employee + assignment, so the same issue is never listed twice however often it is scanned.
 */
final class ComplianceScanner
{
    public const CATEGORY_PROFILE = 'profile';

    public const CATEGORY_REQUIREMENTS = 'job_requirements';

    public const CATEGORY_WORKFLOW = 'workflow';

    /** @return list<string> */
    public static function categories(): array
    {
        return [self::CATEGORY_PROFILE, self::CATEGORY_REQUIREMENTS, self::CATEGORY_WORKFLOW];
    }

    /** The profile fields the registration form requires (StoreEmployeeRequest): without them the record is incomplete. */
    private const REQUIRED_PROFILE_FIELDS = [
        'full_name' => 'Full name',
        'phone' => 'Phone number',
        'location' => 'Location',
        'gender' => 'Gender',
        'employee_category_id' => 'Employee category',
        'application_date' => 'Application date',
    ];

    public function __construct(private readonly EmployeeRegistrationWorkflow $workflow = new EmployeeRegistrationWorkflow) {}

    /**
     * @return array{employees_reviewed: int, findings: list<array<string, mixed>>}
     */
    public function scan(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now()->startOfDay();
        $todayText = $today->toDateString();

        $assignments = $this->assignmentsInScope($todayText)
            ->with(['clientCompany', 'workLocation.clientCompany', 'position', 'salaryHistories'])
            ->orderBy('id')
            ->get();

        $employees = $this->employeesInScope($assignments->pluck('employee_id')->unique()->all())
            ->with('category')
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $byEmployee = $assignments->groupBy('employee_id');
        $activations = $this->latestActivations($employees->keys()->all());
        $findings = [];

        foreach ($employees as $employee) {
            $own = $byEmployee->get($employee->id, collect());
            $primary = $own->sortByDesc(fn (EmployeeWorkAssignment $a): string => $a->start_date->toDateString())->first();
            $activation = $activations->get($employee->id);

            foreach ($this->employeeFindings($employee, $primary, $activation) as $finding) {
                $findings[$finding['key']] = $finding;
            }

            foreach ($own as $assignment) {
                foreach ($this->assignmentFindings($employee, $assignment, $todayText) as $finding) {
                    $findings[$finding['key']] = $finding;
                }
            }
        }

        foreach ($this->coverFindings($todayText) as $finding) {
            $findings[$finding['key']] = $finding;
        }

        return ['employees_reviewed' => $employees->count(), 'findings' => array_values($findings)];
    }

    /**
     * How many people a scan reviews, worked out with two cheap queries and WITHOUT scanning or storing anything. It uses the same scope as scan(), so
     * the figure the read-only report shows matches what an explicit scan reviews.
     */
    public function employeesInScopeCount(?CarbonImmutable $today = null): int
    {
        $todayText = ($today ?? CarbonImmutable::now()->startOfDay())->toDateString();

        return $this->employeesInScope($this->assignmentsInScope($todayText)->pluck('employee_id')->unique()->all())->count();
    }

    /**
     * Who is working (or marked as working): every assignment that is Active, or whose dates include today.
     *
     * @return Builder<EmployeeWorkAssignment>
     */
    private function assignmentsInScope(string $todayText): Builder
    {
        return EmployeeWorkAssignment::query()
            ->where('status', '!=', WorkAssignmentStatus::Cancelled)
            ->where(fn ($q) => $q->where('status', WorkAssignmentStatus::Active)
                ->orWhere(fn ($w) => $w->whereDate('start_date', '<=', $todayText)->where(fn ($e) => $e->whereNull('end_date')->orWhereDate('end_date', '>=', $todayText))));
    }

    /**
     * Every Active employee plus everyone with an assignment in scope.
     *
     * @param  list<int>  $assignedEmployeeIds
     * @return Builder<Employee>
     */
    private function employeesInScope(array $assignedEmployeeIds): Builder
    {
        return Employee::query()->where(fn ($q) => $q->where('status', EmployeeStatus::Active)->orWhereIn('id', $assignedEmployeeIds ?: [0]));
    }

    // ------------------------------------------------------------------ one employee

    /** @return list<array<string, mixed>> */
    private function employeeFindings(Employee $employee, ?EmployeeWorkAssignment $primary, ?EmployeeStatusHistory $activation): array
    {
        $out = [];
        $missingFields = [];

        foreach (self::REQUIRED_PROFILE_FIELDS as $field => $label) {
            $value = $employee->{$field};

            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missingFields[] = $label;
            }
        }

        // The registration rules bind NEW registrations. A record imported from the earlier Excel sources (an "existing" record) was registered before them and
        // is not held to them (the registration workflow says so itself), so the same gaps there are NEEDS HR REVIEW, never a confirmed violation.
        $bound = $this->workflow->appliesTo($employee);
        $confidence = $bound ? HrComplianceFinding::CONFIRMED : HrComplianceFinding::NEEDS_REVIEW;
        $legacy = $bound ? '' : ' This record was registered before these rules applied, so HR should confirm whether the information is needed.';

        if ($missingFields !== []) {
            $out[] = $this->finding(
                $employee, $primary, 'PROFILE_FIELDS_MISSING', self::CATEGORY_PROFILE, 'medium', $confidence,
                'Required profile information is missing: '.implode(', ', $missingFields).'.',
                'The registration form requires these fields; the record is incomplete.'.$legacy,
                'Complete the missing fields on the employee profile.',
                ['missing_fields' => $missingFields, 'registration_origin' => $employee->registration_origin?->value],
                $this->registeredBy($employee),
            );
        }

        if ($employee->pipeline_stage !== null && $employee->status === EmployeeStatus::Active) {
            $out[] = $this->finding(
                $employee, $primary, 'ACTIVE_IN_PIPELINE', self::CATEGORY_WORKFLOW, 'medium', $confidence,
                "The employee is Active but still shows the recruitment stage '{$employee->pipeline_stage->label()}'.",
                'Becoming Active clears the recruitment stage; the record shows two conflicting states.'.$legacy,
                'Review the status history and correct the employee record through the normal HR workflow.',
                ['pipeline_stage' => $employee->pipeline_stage->value, 'registration_origin' => $employee->registration_origin?->value],
                $this->activationActor($activation),
            );
        }

        // An Applicant who was made Active when recorded as a Temporary replacement (owner decision 2026-11-03): Applicant -> Active is not a step of the
        // lifecycle, so HR is told about every such person - whether or not the registration steps were also missing (that is the finding below).
        if ($employee->status === EmployeeStatus::Active && $activation?->source === EmployeeRegistrationWorkflow::SOURCE_COVER_EXCEPTION && $activation->from_status === EmployeeStatus::Applicant) {
            $out[] = $this->finding(
                $employee, $primary, 'APPLICANT_ACTIVATED_FOR_TEMPORARY_COVER', self::CATEGORY_WORKFLOW, 'medium', HrComplianceFinding::NEEDS_REVIEW,
                'An Applicant was made Active and Temporary when recorded as a Temporary replacement.',
                'Applicant -> Active is not a step of the HR lifecycle; it was done as a recorded exception when the cover was saved. Reason on record: '.($activation->override_reason ?? 'not recorded'),
                'Confirm the exception with the person who recorded it and complete the recruitment steps that were skipped.',
                ['exception_reason' => $activation->override_reason, 'exception_source' => $activation->source, 'recorded_on' => $activation->created_at?->toDateString()],
                $this->activationActor($activation),
            );
        }

        if ($this->workflow->appliesTo($employee)) {
            $missing = $this->workflow->missing($employee);

            if ($missing !== []) {
                $exempted = $this->workflow->isExempted($employee);
                $exception = $exempted ? $this->exceptionRow($employee) : null;
                $out[] = $this->finding(
                    $employee, $primary, $exempted ? 'REGISTRATION_EXCEPTION_RECORDED' : 'REGISTRATION_STEPS_INCOMPLETE', self::CATEGORY_REQUIREMENTS,
                    $exempted ? 'medium' : 'high', $exempted ? HrComplianceFinding::NEEDS_REVIEW : HrComplianceFinding::CONFIRMED,
                    'Registration steps not completed: '.implode(', ', $missing).'.',
                    $exempted
                        ? 'The person went past the registration workflow through a recorded exception ('.match ($exception?->source) {
                            EmployeeRegistrationWorkflow::SOURCE_SUCCESSOR_EXCEPTION => 'appointed by HR as a successor', EmployeeRegistrationWorkflow::SOURCE_COVER_EXCEPTION => 'made Active when recorded as a Temporary replacement', default => 'Super Admin override'
                        }.'). Reason on record: '.($exception?->override_reason ?? 'not recorded').' HR should confirm the missing steps are being completed.'
                        : 'A new registration must complete every step (guarantor, contract, uniform, training, practical) before working; the records for these steps do not exist.',
                    $exempted ? 'Confirm the exception with the person who recorded it and complete the missing steps.' : 'Complete the missing registration steps, or record the approved exception.',
                    ['missing_steps' => $missing, 'exception_reason' => $exception?->override_reason, 'exception_source' => $exception?->source],
                    $exempted ? $this->activationActor($exception) : $this->activationActor($activation),
                );
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ one assignment

    /** @return list<array<string, mixed>> */
    private function assignmentFindings(Employee $employee, EmployeeWorkAssignment $a, string $today): array
    {
        $out = [];
        $replacement = $a->assignment_type === AssignmentType::Replacement;
        $by = $this->createdBy($a);
        $add = function (string $code, string $category, string $severity, string $confidence, string $requirement, string $why, string $action, array $details = []) use (&$out, $employee, $a, $by): void {
            $out[] = $this->finding($employee, $a, $code, $category, $severity, $confidence, $requirement, $why, $action, $details, $by);
        };

        if ($a->status === WorkAssignmentStatus::Active && $employee->status !== EmployeeStatus::Active) {
            $add('EMPLOYEE_NOT_ACTIVE', self::CATEGORY_WORKFLOW, 'high', HrComplianceFinding::CONFIRMED,
                "The assignment is Active but the employee's status is '{$employee->status->value}'.",
                'An assignment can only be worked by an Active employee; the employment status conflicts with the appointment.',
                'Correct the status through the HR workflow, or end the assignment.',
                ['employee_status' => $employee->status->value]);
        }

        $company = $a->clientCompany ?? $a->workLocation?->clientCompany;
        $isOffice = $a->workLocation?->location_type?->value === 'office';

        if ($company === null && ! $isOffice) {
            $add('ASSIGNMENT_COMPANY_MISSING', self::CATEGORY_WORKFLOW, 'high', HrComplianceFinding::CONFIRMED,
                'The assignment has no company or workplace.',
                'A company or workplace is mandatory on every assignment.',
                'Open the assignment and record the company or workplace.');
        }

        if (! $replacement && ($a->salary_amount === null || (float) $a->salary_amount <= 0 || $a->salaryHistories->isEmpty())) {
            $add('ASSIGNMENT_SALARY_MISSING', self::CATEGORY_WORKFLOW, 'high', HrComplianceFinding::CONFIRMED,
                'The assignment has no valid salary.',
                'A Permanent assignment must carry a salary and its salary history; payroll cannot calculate this person.',
                'Record the agreed salary through the salary change workflow.');
        }

        $unrecorded = array_values(array_filter([$a->position_id === null ? 'position' : null, $a->shift === null ? 'shift' : null]));

        if ($unrecorded !== []) {
            $add('ASSIGNMENT_DETAILS_NOT_RECORDED', self::CATEGORY_WORKFLOW, 'low', HrComplianceFinding::NEEDS_REVIEW,
                'The assignment does not record the '.implode(' and ', $unrecorded).'.',
                'These are not mandatory in the assignment form, but the position and shift are needed to tell who covers whom; HR should confirm they are not required here.',
                'Record the '.implode(' and ', $unrecorded).' if they apply.', ['not_recorded' => $unrecorded]);
        }

        if ($a->end_date !== null && $a->end_date->lt($a->start_date)) {
            $add('DATES_INCONSISTENT', self::CATEGORY_WORKFLOW, 'high', HrComplianceFinding::CONFIRMED,
                "The end date ({$a->end_date->toDateString()}) is before the start date ({$a->start_date->toDateString()}).",
                'An assignment cannot end before it starts.', 'Correct the dates of the assignment.');
        } elseif ($a->status === WorkAssignmentStatus::Active && $a->end_date !== null && $a->end_date->toDateString() < $today) {
            $add('DATES_INCONSISTENT', self::CATEGORY_WORKFLOW, 'high', HrComplianceFinding::CONFIRMED,
                "The assignment is Active but its end date ({$a->end_date->toDateString()}) has passed.",
                'The status and the dates of the assignment disagree.', 'End the assignment, or correct its end date.');
        } elseif ($a->status === WorkAssignmentStatus::Ended && $a->end_date === null) {
            $add('DATES_INCONSISTENT', self::CATEGORY_WORKFLOW, 'high', HrComplianceFinding::CONFIRMED,
                'The assignment is Ended but has no end date.', 'An ended assignment must record its last day.', 'Record the end date.');
        }

        if ($replacement) {
            foreach ($this->replacementProblems($employee, $a) as [$code, $requirement, $why, $action]) {
                $add($code, self::CATEGORY_WORKFLOW, 'high', HrComplianceFinding::CONFIRMED, $requirement, $why, $action);
            }
        } elseif ($a->replaces_assignment_id !== null) {
            $before = EmployeeWorkAssignment::query()->find($a->replaces_assignment_id);

            if ($before !== null && $before->assignment_type === AssignmentType::Permanent && ($before->end_date === null || $before->end_date->gte($a->start_date)) && $before->status !== WorkAssignmentStatus::Cancelled) {
                $add('SUCCESSOR_OVERLAP', self::CATEGORY_WORKFLOW, 'high', HrComplianceFinding::CONFIRMED,
                    "This assignment takes over #{$before->id}, which is still open or ends on or after this one starts.",
                    'Two people cannot hold the same position on the same day.', 'End the previous assignment before the day this one starts, or correct the dates.',
                    ['predecessor_assignment_id' => $before->id]);
            }
        }

        return $out;
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> [code, requirement, why, action] */
    private function replacementProblems(Employee $employee, EmployeeWorkAssignment $a): array
    {
        $out = [];

        if ($employee->employment_type === 'temporary') {
            $out[] = ['REPLACEMENT_IS_TEMPORARY_EMPLOYEE', "A Temporary employee ({$employee->employee_number}) is registered as a Replacement.",
                'A Temporary is paid through a Temporary cover, not as a Replacement who takes over a position; payroll cannot calculate this assignment.',
                'Record the work as a Temporary cover, or correct the classification.'];
        }

        if ($a->replaces_assignment_id === null) {
            return [...$out, ['REPLACEMENT_LINK_MISSING', 'The Replacement does not record which assignment it replaces.',
                'Without the original assignment nobody can tell whose position is covered or who returns.', 'Link the Replacement to the original assignment.']];
        }

        $original = EmployeeWorkAssignment::query()->find($a->replaces_assignment_id);

        if ($original === null || $original->status === WorkAssignmentStatus::Cancelled) {
            return [...$out, ['REPLACEMENT_LINK_INVALID', 'The assignment this Replacement replaces is '.($original === null ? 'missing' : 'cancelled').'.',
                'A Replacement must cover an existing assignment.', 'Correct the link, or end the Replacement.']];
        }

        if ($a->start_date->lt($original->start_date)) {
            $out[] = ['REPLACEMENT_LINK_INVALID', "The Replacement starts ({$a->start_date->toDateString()}) before the assignment it covers ({$original->start_date->toDateString()}).",
                'A cover cannot start before the position exists.', 'Correct the dates.'];
        }

        // While the original is still open, the Replacement's days must fall inside a recorded absence of the original; otherwise both could be paid.
        $originalOpenOnReplacementStart = $original->end_date === null || $original->end_date->gte($a->start_date);

        if ($originalOpenOnReplacementStart && ! $this->insideAbsence($original, $a)) {
            $out[] = ['REPLACEMENT_NOT_IN_ABSENCE', "The Replacement works while #{$original->id} is not recorded as away.",
                'The original assignment is still open on those days with no recorded absence, so two people could be paid for one position and day.',
                'Record the absence of the original employee, or correct the Replacement dates.'];
        }

        return $out;
    }

    private function insideAbsence(EmployeeWorkAssignment $original, EmployeeWorkAssignment $replacement): bool
    {
        $start = $replacement->start_date->toDateString();
        $end = $replacement->end_date?->toDateString();

        return AssignmentAbsence::query()->where('work_assignment_id', $original->id)->get()->contains(function (AssignmentAbsence $absence) use ($start, $end): bool {
            $from = $absence->absent_from->toDateString();
            $to = $absence->returned_on?->copy()->subDay()->toDateString();

            return $start >= $from && ($to === null || ($end !== null && $end <= $to));
        });
    }

    // ------------------------------------------------------------------ Temporary covers

    /** @return list<array<string, mixed>> */
    private function coverFindings(string $today): array
    {
        $out = [];
        $covers = TemporaryReplacement::query()
            ->with(['workAssignment.clientCompany', 'workAssignment.workLocation.clientCompany', 'workAssignment.position', 'replacementEmployee.category'])
            ->whereNull('approved_at')
            ->whereDate('start_date', '<=', $today)
            ->get();

        foreach ($covers as $cover) {
            $worker = $cover->replacementEmployee;
            $position = $cover->workAssignment;

            if ($worker === null || $position === null) {
                continue;
            }

            $out[] = $this->finding(
                $worker, $position, 'COVER_NOT_APPROVED', self::CATEGORY_WORKFLOW, 'medium', HrComplianceFinding::NEEDS_REVIEW,
                "The Temporary cover that started on {$cover->start_date->toDateString()} has not been approved.",
                'Nothing is deducted from the covered Permanent until the cover is approved; HR should confirm whether the cover was authorised.',
                'Approve the cover, or end it.',
                ['temporary_replacement_id' => $cover->id],
                ['admin_id' => $cover->created_by, 'action' => 'Created the Temporary cover', 'at' => $cover->created_at],
                key: "COVER_NOT_APPROVED:{$worker->id}:{$cover->id}",
                typeOverride: 'Temporary',
                appointedOn: $cover->start_date->toDateString(),
            );
        }

        return $out;
    }

    // ------------------------------------------------------------------ building blocks

    /**
     * @param  array<string, mixed>  $details
     * @param  array{admin_id: ?int, action: string, at: mixed}|null  $by
     * @return array<string, mixed>
     */
    private function finding(
        Employee $employee,
        ?EmployeeWorkAssignment $a,
        string $code,
        string $category,
        string $severity,
        string $confidence,
        string $requirement,
        string $explanation,
        string $action,
        array $details,
        ?array $by,
        ?string $key = null,
        ?string $typeOverride = null,
        ?string $appointedOn = null,
    ): array {
        $company = $a?->clientCompany ?? $a?->workLocation?->clientCompany;
        $adminId = $by['admin_id'] ?? null;

        return [
            'key' => $key ?? "{$code}:{$employee->id}:".($a?->id ?? 0),
            'category' => $category,
            'code' => $code,
            'severity' => $severity,
            'confidence' => $confidence,
            'employee_id' => $employee->id,
            'assignment_id' => $a?->id,
            'client_company_id' => $company?->id,
            'employee_name' => $employee->full_name,
            'employee_number' => $employee->employee_number,
            'company_name' => $company?->name,
            'position_name' => $a?->position?->name,
            'shift' => $a?->shift?->value,
            'employment_type' => $typeOverride ?? $this->employmentType($employee, $a),
            'employment_status' => $employee->status->value,
            'assignment_status' => $a?->status->value,
            'requirement' => $requirement,
            'explanation' => $explanation,
            'recommended_action' => $action,
            'details' => $details,
            // Only what a record says: no admin id means "Responsible manager not recorded".
            'responsible_admin_id' => $adminId,
            'responsible_action' => $adminId === null ? null : $by['action'],
            'responsible_at' => $adminId === null ? null : $by['at'],
            'appointed_on' => $appointedOn ?? $a?->start_date->toDateString(),
        ];
    }

    private function employmentType(Employee $employee, ?EmployeeWorkAssignment $a): string
    {
        if ($a?->assignment_type === AssignmentType::Replacement) {
            return 'Replacement';
        }

        return $employee->employment_type === 'temporary' ? 'Temporary' : 'Permanent';
    }

    /** @return array{admin_id: ?int, action: string, at: mixed}|null */
    private function createdBy(EmployeeWorkAssignment $a): ?array
    {
        return $a->created_by === null ? null : ['admin_id' => $a->created_by, 'action' => 'Created the assignment', 'at' => $a->created_at];
    }

    /** @return array{admin_id: ?int, action: string, at: mixed}|null */
    private function registeredBy(Employee $employee): ?array
    {
        return $employee->created_by === null ? null : ['admin_id' => $employee->created_by, 'action' => 'Registered the employee', 'at' => $employee->created_at];
    }

    /** @return array{admin_id: ?int, action: string, at: mixed}|null */
    private function activationActor(?EmployeeStatusHistory $row): ?array
    {
        return $row === null || $row->changed_by === null ? null : ['admin_id' => $row->changed_by, 'action' => 'Changed the status to Active', 'at' => $row->created_at];
    }

    private function exceptionRow(Employee $employee): ?EmployeeStatusHistory
    {
        return EmployeeStatusHistory::query()
            ->where('employee_id', $employee->id)
            ->whereIn('source', EmployeeRegistrationWorkflow::EXCEPTION_SOURCES)
            ->whereNotNull('override_reason')
            ->latest('id')
            ->first();
    }

    /**
     * The most recent change to Active of each employee (who made it and when).
     *
     * @param  list<int>  $employeeIds
     * @return Collection<int, EmployeeStatusHistory>
     */
    private function latestActivations(array $employeeIds): Collection
    {
        return EmployeeStatusHistory::query()
            ->whereIn('employee_id', $employeeIds ?: [0])
            ->where('to_status', EmployeeStatus::Active->value)
            ->orderBy('id')
            ->get()
            ->keyBy('employee_id');
    }
}
