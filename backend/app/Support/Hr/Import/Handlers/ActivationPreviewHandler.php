<?php

namespace App\Support\Hr\Import\Handlers;

use App\Enums\EmployeeStatus;
use App\Enums\ImportType;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\ClientCompanyAlias;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Support\Hr\EmployeeDuplicateFinder;
use App\Support\Hr\EmployeeTransitionGuard;
use App\Support\Hr\Import\ImportNormalizer;
use App\Support\Hr\Import\ImportTypeHandler;
use App\Support\Hr\Import\RowOutcome;
use App\Support\Hr\Import\StagedRow;
use App\Support\Hr\WorkbookPlan\WorkbookPlanExecutor;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY activation preview for the approved employee plan (columns of employees_plan_220_ready.csv:
 * source_row, employee_id, employee_number, name_key, expected_status, company, salary, start_date).
 *
 * It verifies each planned employee against the live database - identity, status, duplicate person
 * records, company, pay data - and states the transition path that activation would take and why. It
 * NEVER activates, assigns or changes anything, and it cannot be confirmed. A successful preview is not an
 * authorisation: production activation needs its own explicit approval.
 *
 * Row meaning: valid = identity verified, a transition path exists; duplicate = already Active or repeated
 * in the file; review = a person must look (possible duplicate person record, missing pay data); invalid =
 * identity or status does not match the plan.
 */
final class ActivationPreviewHandler implements ImportTypeHandler
{
    public const PATH_ALREADY_ACTIVE = 'already_active';

    public const PATH_NORMAL = 'normal_step';

    public const PATH_REHIRE = 'rehire';

    public const PATH_OVERRIDE = 'super_admin_override_required';

    public function __construct(
        private readonly EmployeeTransitionGuard $guard,
        private readonly EmployeeDuplicateFinder $duplicates,
    ) {}

    public function type(): ImportType
    {
        return ImportType::ActivationPreview;
    }

    public function columns(): array
    {
        return [
            'source_row' => [],
            'employee_id' => [],
            'employee_number' => [],
            'name_key' => [],
            'expected_status' => [],
            'company' => [],
            'salary' => [],
            'start_date' => [],
        ];
    }

    public function requiredColumns(): array
    {
        return ['employee_id', 'employee_number', 'name_key'];
    }

    public function ignoredColumns(): array
    {
        return [];
    }

    public function stage(array $cells, array &$seen): StagedRow
    {
        $sourceRow = trim($cells['source_row'] ?? '');
        $idText = trim($cells['employee_id'] ?? '');
        $number = trim($cells['employee_number'] ?? '');
        $planKey = trim($cells['name_key'] ?? '');
        $expected = mb_strtolower(trim($cells['expected_status'] ?? ''));
        $company = trim($cells['company'] ?? '');
        $salary = trim($cells['salary'] ?? '');
        $startRaw = trim($cells['start_date'] ?? '');

        if (! ctype_digit($idText) || $number === '' || $planKey === '') {
            return StagedRow::invalid('ROW_MALFORMED', 'employee_id (a whole number), employee_number and name_key are required.');
        }

        $id = (int) $idText;

        if (isset($seen['ids'][$id])) {
            return StagedRow::valid([], null, null)->asRepeatInFile('employee');
        }

        $seen['ids'][$id] = true;

        $employee = Employee::withTrashed()->find($id);
        $base = ['source_row' => $sourceRow, 'employee_number' => $number, 'expected_status' => $expected, 'company' => $company];

        if ($employee === null) {
            return StagedRow::invalid('EMPLOYEE_NOT_FOUND', 'No employee with this id exists.', null, null, null, ['report' => $this->report($base, null, 'none', 'no', 'EMPLOYEE_NOT_FOUND')]);
        }

        if ($employee->trashed()) {
            return StagedRow::invalid('EMPLOYEE_ARCHIVED', 'The employee record is archived.', null, null, null, ['report' => $this->report($base, $employee, 'none', 'no', 'EMPLOYEE_ARCHIVED')]);
        }

        if ($employee->employee_number !== $number || WorkbookPlanExecutor::nameKey($employee->full_name) !== $planKey) {
            return StagedRow::invalid('IDENTITY_MISMATCH', "Employee {$employee->employee_number} does not match the plan row (number or name differs).", null, null, null, ['report' => $this->report($base, $employee, 'none', 'no', 'IDENTITY_MISMATCH')]);
        }

        if ($employee->status === EmployeeStatus::Active) {
            return StagedRow::duplicate('ALREADY_ACTIVE', 'The employee is already Active; nothing to do.', null, null, null, ['report' => $this->report($base, $employee, self::PATH_ALREADY_ACTIVE, 'no')], Employee::class, $employee->id);
        }

        if ($expected !== '' && $expected !== $employee->status->value) {
            return StagedRow::invalid('STATUS_CHANGED_SINCE_PLAN', "The plan expected status '{$expected}' but the employee is '{$employee->status->value}'.", null, null, null, ['report' => $this->report($base, $employee, 'none', 'no', 'STATUS_CHANGED_SINCE_PLAN')]);
        }

        [$path, $needsOverride, $pathText] = $this->path($employee->status);
        $warnings = [];

        $others = $this->duplicates->find([
            'phone' => $employee->phone,
            'alternate_phone' => $employee->alternate_phone,
            'full_name' => $employee->full_name,
        ], $employee->id);

        $companyRegistered = $company !== '' && $this->companyKnown($company, $seen);

        if ($company === '') {
            $warnings[] = 'COMPANY_MISSING';
        } elseif (! $companyRegistered) {
            $warnings[] = 'COMPANY_NOT_REGISTERED_YET';
        }

        $payOk = $salary !== '' && is_numeric($salary) && (float) $salary > 0 && ImportNormalizer::date($startRaw) !== null;

        if (! $payOk) {
            $warnings[] = 'SALARY_OR_START_MISSING';
        }

        $completions = DB::table('employee_historical_completions')->where('employee_id', $employee->id)->distinct()->count('stage');
        $report = $this->report($base, $employee, $path, $needsOverride ? 'yes' : 'no', '', $pathText, $companyRegistered ? 'yes' : 'no', (string) $completions, implode(' ', $warnings));
        $meta = ['report' => $report, 'path' => $path, 'warnings' => $warnings];

        if ($others->isNotEmpty()) {
            $meta['employee_numbers'] = $others->pluck('employee_number')->all();

            return StagedRow::review('DUPLICATE_PERSON_RECORD', 'Another employee record shares this person\'s phone or full name. Resolve it before activation.', null, null, null, $meta, Employee::class, $employee->id);
        }

        if (! $payOk) {
            return StagedRow::review('SALARY_OR_START_MISSING', 'A positive monthly salary and a valid start date are needed for the assignment step; none is invented.', null, null, null, $meta, Employee::class, $employee->id);
        }

        return StagedRow::valid([], null, null, $meta, Employee::class, $employee->id, $pathText);
    }

    public function process(ImportRow $row, ImportBatch $batch, Admin $actor): RowOutcome
    {
        return RowOutcome::failed('PREVIEW_ONLY', 'An activation preview never changes any employee.');
    }

    /**
     * @return array{string, bool, string}
     */
    private function path(EmployeeStatus $from): array
    {
        if ($from === EmployeeStatus::Inactive) {
            return [self::PATH_REHIRE, false, 'Inactive to Active through Rehire (separation history is kept).'];
        }

        if ($this->guard->evaluate($from, EmployeeStatus::Active) === EmployeeTransitionGuard::DECISION_NORMAL) {
            return [self::PATH_NORMAL, false, "{$from->label()} to Active is a normal lifecycle step."];
        }

        return [self::PATH_OVERRIDE, true, "{$from->label()} to Active is not a normal lifecycle step; it needs a Super Admin override with a written reason."];
    }

    /**
     * @param  array<string, string>  $base
     * @return array<string, string>
     */
    private function report(array $base, ?Employee $employee, string $path, string $needsOverride, string $problem = '', string $pathText = '', string $companyRegistered = '', string $completions = '', string $warnings = ''): array
    {
        return [
            'source_row' => $base['source_row'],
            'employee_number' => $base['employee_number'],
            'current_status' => $employee?->status->value ?? '',
            'expected_status' => $base['expected_status'],
            'proposed_path' => $path,
            'requires_super_admin_override' => $needsOverride,
            'path_explanation' => $pathText,
            'company' => $base['company'],
            'company_registered' => $companyRegistered,
            'historical_completion_stages' => $completions,
            'warnings' => trim($warnings.' '.$problem),
        ];
    }

    /**
     * @param  array<string, mixed>  $seen
     */
    private function companyKnown(string $company, array &$seen): bool
    {
        $seen['company_keys'] ??= collect(ClientCompany::query()->pluck('name'))
            ->merge(ClientCompanyAlias::query()->pluck('alias'))
            ->map(fn (string $name): string => ImportNormalizer::nameKey($name))
            ->flip()
            ->all();

        return isset($seen['company_keys'][ImportNormalizer::nameKey($company)]);
    }
}
