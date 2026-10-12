<?php

namespace App\Support\Hr\WorkbookPlan;

use App\Actions\Hr\BulkActivateEmployeesAction;
use App\Actions\Hr\CreateClientCompanyAction;
use App\Actions\Hr\CreateWorkAssignmentAction;
use App\Enums\EmployeeStatus;
use App\Enums\WorkAssignmentStatus;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\ClientCompanyAlias;
use App\Models\Employee;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Applies an APPROVED plan derived from the company workbook, one record at a
 * time, in three independent steps: register companies (with their original
 * Excel spellings kept as aliases), move the listed employees to Active, and
 * assign each to its company. Every record is checked against the live
 * database first (identity, status, existing company/assignment) and succeeds
 * or fails on its own with a reason. Re-running is safe: nothing that already
 * exists is created again. In dry-run mode no write happens, and later steps
 * assume the earlier steps of the same run would succeed so the whole plan can
 * be previewed end to end.
 */
class WorkbookPlanExecutor
{
    public function __construct(
        private readonly CreateClientCompanyAction $createCompany,
        private readonly BulkActivateEmployeesAction $bulkActivate,
        private readonly CreateWorkAssignmentAction $createAssignment,
    ) {}

    public static function nameKey(?string $name): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) $name) ?? '');
    }

    /**
     * @param  list<array{name: string, category: string, aliases: list<array{alias: string, reference: ?string}>}>  $rows
     * @return list<array<string, mixed>>
     */
    public function companies(array $rows, bool $dryRun, ?Admin $actor): array
    {
        $results = [];

        foreach ($rows as $row) {
            $existing = ClientCompany::query()->whereRaw('lower(name) = ?', [mb_strtolower(trim($row['name']))])->first();
            $base = ['company' => $row['name'], 'category' => $row['category']];

            if ($existing !== null) {
                $warn = $existing->category->value !== $row['category'] ? " (existing category is '{$existing->category->value}', plan says '{$row['category']}'; left unchanged)" : '';
                $this->aliases($existing, $row['aliases'], $dryRun, $actor);
                $results[] = [...$base, 'outcome' => 'exists', 'code' => null, 'message' => 'Already registered; nothing created.'.$warn];

                continue;
            }

            if ($dryRun) {
                $results[] = [...$base, 'outcome' => 'would_create', 'code' => null, 'message' => 'Would be registered with '.count($row['aliases']).' original spelling(s) kept.'];

                continue;
            }

            try {
                $company = $this->createCompany->handle(['name' => $row['name'], 'category' => $row['category']], $actor);
                $this->aliases($company, $row['aliases'], false, $actor);
                $results[] = [...$base, 'outcome' => 'created', 'code' => null, 'message' => 'Registered.'];
            } catch (\Throwable $e) {
                $results[] = [...$base, 'outcome' => 'failed', 'code' => 'COMPANY_CREATE_FAILED', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * @param  list<array<string, mixed>>  $employees
     * @return array{dry_run: bool, summary: array<string, int>, results: list<array<string, mixed>>}
     */
    public function status(array $employees, string $note, ?string $joiningDate, bool $dryRun, ?Admin $actor, ?string $overrideReason = null): array
    {
        $records = array_map(fn (array $e): array => [
            'employee_id' => (int) $e['employee_id'],
            'source_ref' => 'EMPLOYEE AND COMPANY.xlsx row '.$e['source_row'],
            'expected_employee_number' => $e['employee_number'],
            'expected_status' => $e['expected_status'] ?: null,
        ], $employees);

        $admin = $actor ?? new Admin(['id' => 0]);

        return $this->bulkActivate->handle($records, $note, $joiningDate, $dryRun, $admin, $overrideReason);
    }

    /**
     * @param  list<array<string, mixed>>  $employees
     * @param  list<int>  $assumeActive  employee ids that the status step (same dry run) would activate
     * @param  list<string>  $assumeCompanies  lower-cased names the companies step (same dry run) would register
     * @return list<array<string, mixed>>
     */
    public function assignments(array $employees, bool $dryRun, ?Admin $actor, array $assumeActive = [], array $assumeCompanies = []): array
    {
        $results = [];

        foreach ($employees as $row) {
            $base = ['source_row' => $row['source_row'], 'employee_id' => (int) $row['employee_id'], 'company' => $row['company']];
            $employee = Employee::withTrashed()->find((int) $row['employee_id']);

            if ($employee === null || $employee->trashed()) {
                $results[] = [...$base, 'outcome' => 'failed', 'code' => 'EMPLOYEE_NOT_FOUND', 'message' => 'Employee missing or archived.'];

                continue;
            }

            if ($employee->employee_number !== $row['employee_number'] || self::nameKey($employee->full_name) !== $row['name_key']) {
                $results[] = [...$base, 'outcome' => 'failed', 'code' => 'IDENTITY_MISMATCH', 'message' => "Employee {$employee->employee_number} does not match the approved plan row (number or name differs)."];

                continue;
            }

            if ($employee->status !== EmployeeStatus::Active && ! ($dryRun && in_array($employee->id, $assumeActive, true))) {
                $results[] = [...$base, 'outcome' => 'failed', 'code' => 'EMPLOYEE_NOT_ACTIVE', 'message' => "Employee is {$employee->status->value}; run the status step first."];

                continue;
            }

            $company = ClientCompany::query()->whereRaw('lower(name) = ?', [mb_strtolower(trim($row['company']))])->first();

            if ($company === null && ! ($dryRun && in_array(mb_strtolower(trim($row['company'])), $assumeCompanies, true))) {
                $results[] = [...$base, 'outcome' => 'failed', 'code' => 'COMPANY_NOT_REGISTERED', 'message' => "Company '{$row['company']}' is not registered."];

                continue;
            }

            if ($row['salary'] === '' || ! is_numeric($row['salary']) || (float) $row['salary'] <= 0 || empty($row['start_date'])) {
                $results[] = [...$base, 'outcome' => 'failed', 'code' => 'SALARY_OR_START_MISSING', 'message' => 'A positive monthly salary and a start date are required; none is invented.'];

                continue;
            }

            if ($company !== null && $employee->workAssignments()->where('status', WorkAssignmentStatus::Active)->where('client_company_id', $company->id)->exists()) {
                $results[] = [...$base, 'outcome' => 'already_assigned', 'code' => null, 'message' => 'Already has an active assignment at this company; nothing created.'];

                continue;
            }

            if ($dryRun) {
                $results[] = [...$base, 'outcome' => 'would_assign', 'code' => null, 'message' => "Would assign at {$row['salary']} USD monthly from {$row['start_date']}."];

                continue;
            }

            try {
                $this->createAssignment->handle($employee, [
                    'client_company_id' => $company->id,
                    'start_date' => $row['start_date'],
                    'salary_amount' => $row['salary'],
                    'salary_currency' => 'USD',
                    'salary_frequency' => 'monthly',
                    'notes' => 'Assigned from the approved company workbook, EMPLOYEE AND COMPANY.xlsx row '.$row['source_row'].'.',
                ], $actor);
                $results[] = [...$base, 'outcome' => 'assigned', 'code' => null, 'message' => 'Assigned.'];
            } catch (HttpResponseException $e) {
                $payload = $e->getResponse()->getData(true);
                $results[] = [...$base, 'outcome' => 'failed', 'code' => $payload['error_code'] ?? 'FAILED', 'message' => $payload['message'] ?? 'Failed.'];
            } catch (\Throwable $e) {
                $results[] = [...$base, 'outcome' => 'failed', 'code' => 'UNEXPECTED_ERROR', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * @param  list<array{alias: string, reference: ?string}>  $aliases
     */
    private function aliases(ClientCompany $company, array $aliases, bool $dryRun, ?Admin $actor): void
    {
        if ($dryRun) {
            return;
        }

        foreach ($aliases as $alias) {
            ClientCompanyAlias::query()->firstOrCreate(
                ['client_company_id' => $company->id, 'alias' => $alias['alias']],
                ['source' => 'EMPLOYEE AND COMPANY.xlsx', 'source_reference' => $alias['reference'], 'created_by' => $actor?->id],
            );
        }
    }
}
