<?php

namespace App\Support\Hr\WorkbookPlan;

use App\Actions\Hr\BulkActivateEmployeesAction;
use App\Actions\Hr\CreateClientCompanyAction;
use App\Actions\Hr\CreateWorkAssignmentAction;
use App\Actions\Hr\UpdateWorkAssignmentAction;
use App\Enums\AssignmentShift;
use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Http\Requests\Api\V1\Admin\Hr\EmployeePaymentAccountRule;
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
    /** The workbook the approved plan came from; shown in status history, assignment notes and alias sources. */
    public const DEFAULT_SOURCE = 'EMPLOYEE AND COMPANY.xlsx';

    public function __construct(
        private readonly CreateClientCompanyAction $createCompany,
        private readonly BulkActivateEmployeesAction $bulkActivate,
        private readonly CreateWorkAssignmentAction $createAssignment,
        private readonly UpdateWorkAssignmentAction $updateAssignment,
    ) {}

    public static function nameKey(?string $name): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) $name) ?? '');
    }

    /**
     * @param  list<array{name: string, category: string, aliases: list<array{alias: string, reference: ?string}>}>  $rows
     * @return list<array<string, mixed>>
     */
    public function companies(array $rows, bool $dryRun, ?Admin $actor, string $source = self::DEFAULT_SOURCE): array
    {
        $results = [];

        foreach ($rows as $row) {
            $existing = ClientCompany::query()->whereRaw('lower(name) = ?', [mb_strtolower(trim($row['name']))])->first();
            $base = ['company' => $row['name'], 'category' => $row['category']];

            if ($existing !== null) {
                $warn = $existing->category->value !== $row['category'] ? " (existing category is '{$existing->category->value}', plan says '{$row['category']}'; left unchanged)" : '';
                $this->aliases($existing, $row['aliases'], $dryRun, $actor, $source);
                $results[] = [...$base, 'outcome' => 'exists', 'code' => null, 'message' => 'Already registered; nothing created.'.$warn];

                continue;
            }

            if ($dryRun) {
                $results[] = [...$base, 'outcome' => 'would_create', 'code' => null, 'message' => 'Would be registered with '.count($row['aliases']).' original spelling(s) kept.'];

                continue;
            }

            try {
                $company = $this->createCompany->handle(['name' => $row['name'], 'category' => $row['category']], $actor);
                $this->aliases($company, $row['aliases'], false, $actor, $source);
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
    public function status(array $employees, string $note, ?string $joiningDate, bool $dryRun, ?Admin $actor, ?string $overrideReason = null, string $source = self::DEFAULT_SOURCE): array
    {
        // One person can be on several rows (one per company); the status step handles each person once.
        $employees = array_values(array_reduce($employees, function (array $carry, array $e): array {
            $carry[(int) $e['employee_id']] ??= $e;

            return $carry;
        }, []));

        $records = array_map(fn (array $e): array => [
            'employee_id' => (int) $e['employee_id'],
            'source_ref' => $source.' row '.$e['source_row'],
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
    public function assignments(array $employees, bool $dryRun, ?Admin $actor, array $assumeActive = [], array $assumeCompanies = [], string $source = self::DEFAULT_SOURCE): array
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

            $shift = trim((string) ($row['shift'] ?? ''));

            if ($shift !== '' && ! in_array($shift, AssignmentShift::values(), true)) {
                $results[] = [...$base, 'outcome' => 'failed', 'code' => 'INVALID_SHIFT', 'message' => "Shift '{$shift}' is not day or night; nothing is guessed."];

                continue;
            }

            $existing = $company === null ? null : $employee->workAssignments()->where('status', WorkAssignmentStatus::Active)->where('client_company_id', $company->id)->first();

            if ($existing !== null) {
                // The assignment is already there. Only its (still empty) shift may be recorded, through the audited edit; salary, dates,
                // type and company are never touched, and a different shift already on record is reported, not overwritten.
                if ($shift === '' || $existing->shift?->value === $shift) {
                    $results[] = [...$base, 'outcome' => 'already_assigned', 'code' => null, 'message' => 'Already has an active assignment at this company; nothing created.'];

                    continue;
                }

                if ($existing->shift !== null) {
                    $results[] = [...$base, 'outcome' => 'failed', 'code' => 'SHIFT_CONFLICT', 'message' => "The assignment already has the {$existing->shift->value} shift on record; the plan says {$shift}. Nothing changed."];

                    continue;
                }

                if ((int) round(((float) $existing->salary_amount) * 100) !== (int) round(((float) $row['salary']) * 100)) {
                    $results[] = [...$base, 'outcome' => 'failed', 'code' => 'SALARY_DIFFERS_FROM_RECORD', 'message' => "The existing assignment pays {$existing->salary_amount}, the plan row says {$row['salary']}; the shift is not recorded on a different assignment."];

                    continue;
                }

                if ($dryRun) {
                    $results[] = [...$base, 'outcome' => 'would_set_shift', 'code' => null, 'message' => "Would record the {$shift} shift on the existing assignment (nothing else changes)."];

                    continue;
                }

                try {
                    $this->updateAssignment->handle($existing, ['shift' => $shift], $actor);
                    $results[] = [...$base, 'outcome' => 'shift_set', 'code' => null, 'message' => "Recorded the {$shift} shift on the existing assignment."];
                } catch (HttpResponseException $e) {
                    $payload = $e->getResponse()->getData(true);
                    $results[] = [...$base, 'outcome' => 'failed', 'code' => $payload['error_code'] ?? 'FAILED', 'message' => $payload['message'] ?? 'Failed.'];
                }

                continue;
            }

            if ($dryRun) {
                $results[] = [...$base, 'outcome' => 'would_assign', 'code' => null, 'message' => "Would assign at {$row['salary']} USD monthly from {$row['start_date']}".($shift !== '' ? ", {$shift} shift" : '').'.'];

                continue;
            }

            try {
                $this->createAssignment->handle($employee, [
                    'client_company_id' => $company->id,
                    'start_date' => $row['start_date'],
                    'salary_amount' => $row['salary'],
                    'salary_currency' => 'USD',
                    'salary_frequency' => 'monthly',
                    'shift' => $shift !== '' ? $shift : null,
                    'notes' => 'Assigned from the approved company workbook, '.$source.' row '.$row['source_row'].'.',
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
     * Fills the employee's separate payment/account number (the number salary is sent to) from the workbook's phone column.
     * It is NEVER the registration phone: `phone` is not read or written here, an existing payment number is never
     * overwritten (a different one is reported as a conflict), and a row without a number is skipped, not guessed.
     *
     * @param  list<array<string, mixed>>  $employees  rows carrying an optional payment_account_number column
     * @return list<array<string, mixed>>
     */
    public function paymentNumbers(array $employees, bool $dryRun, ?Admin $actor, string $source = self::DEFAULT_SOURCE): array
    {
        $results = [];

        foreach ($employees as $row) {
            $base = ['source_row' => $row['source_row'], 'employee_id' => (int) $row['employee_id'], 'company' => null];
            $number = trim((string) ($row['payment_account_number'] ?? ''));
            $employee = Employee::withTrashed()->find((int) $row['employee_id']);

            if ($number === '') {
                $results[] = [...$base, 'outcome' => 'skipped_no_number', 'code' => null, 'message' => 'The plan row carries no payment number; nothing is guessed.'];

                continue;
            }

            if ($employee === null || $employee->trashed()) {
                $results[] = [...$base, 'outcome' => 'failed', 'code' => 'EMPLOYEE_NOT_FOUND', 'message' => 'Employee missing or archived.'];

                continue;
            }

            if ($employee->employee_number !== $row['employee_number'] || self::nameKey($employee->full_name) !== $row['name_key']) {
                $results[] = [...$base, 'outcome' => 'failed', 'code' => 'IDENTITY_MISMATCH', 'message' => "Employee {$employee->employee_number} does not match the approved plan row (number or name differs)."];

                continue;
            }

            if (! EmployeePaymentAccountRule::isValid($number)) {
                $results[] = [...$base, 'outcome' => 'failed', 'code' => 'INVALID_PAYMENT_NUMBER', 'message' => 'The payment number has characters or a length that is not allowed.'];

                continue;
            }

            $existing = trim((string) $employee->payment_account_number);

            if ($existing !== '') {
                $results[] = $existing === $number
                    ? [...$base, 'outcome' => 'already_set', 'code' => null, 'message' => 'The same payment number is already recorded; nothing changed.']
                    : [...$base, 'outcome' => 'failed', 'code' => 'PAYMENT_NUMBER_CONFLICT', 'message' => 'A different payment number is already recorded; it is not overwritten.'];

                continue;
            }

            if ($dryRun) {
                $results[] = [...$base, 'outcome' => 'would_set', 'code' => null, 'message' => 'Would record the payment number (registration phone untouched).'];

                continue;
            }

            $employee->update(['payment_account_number' => $number]);

            if ($actor !== null) {
                event(AuditEvent::record(
                    action: AuditAction::Update,
                    admin: $actor,
                    description: "Payment number recorded for employee {$employee->employee_number} from the approved workbook, ".$source.' row '.$row['source_row'].'.',
                    entityType: Employee::class,
                    entityId: $employee->id,
                    metadata: ['field' => 'payment_account_number'],
                ));
            }

            $results[] = [...$base, 'outcome' => 'set', 'code' => null, 'message' => 'Payment number recorded (registration phone untouched).'];
        }

        return $results;
    }

    /**
     * @param  list<array{alias: string, reference: ?string}>  $aliases
     */
    private function aliases(ClientCompany $company, array $aliases, bool $dryRun, ?Admin $actor, string $source): void
    {
        if ($dryRun) {
            return;
        }

        foreach ($aliases as $alias) {
            ClientCompanyAlias::query()->firstOrCreate(
                ['client_company_id' => $company->id, 'alias' => $alias['alias']],
                ['source' => $source, 'source_reference' => $alias['reference'], 'created_by' => $actor?->id],
            );
        }
    }
}
