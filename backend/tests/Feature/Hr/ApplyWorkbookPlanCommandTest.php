<?php

namespace Tests\Feature\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\ClientCompanyAlias;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeWorkAssignment;
use App\Support\Hr\WorkbookPlan\WorkbookPlanExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

class ApplyWorkbookPlanCommandTest extends TestCase
{
    private const OVERRIDE = 'Required processes completed; confirmed by the owner for the approved workbook.';

    use HrFixtures;
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'plan_'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function csv(string $name, array $header, array $rows): string
    {
        $path = $this->dir.DIRECTORY_SEPARATOR.$name;
        $h = fopen($path, 'w');
        fputcsv($h, $header, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($h, $row, ',', '"', '\\');
        }
        fclose($h);

        return $path;
    }

    private function companiesFile(): string
    {
        return $this->csv('companies.csv', ['name', 'category', 'aliases'], [
            ['MarwaFertility', 'company', 'MarwaFertility @ rows 170,171|MARWAFERTILITY @ rows 174,175'],
            ['TransitHotel', 'company', 'TransitHotel @ rows 117|TRANSITHOTEL @ row 120'],
            ['GURI TEST', 'guri', 'GURI TEST @ row 91'],
        ]);
    }

    /** @return array{0: string, 1: array<int, Employee>} */
    private function employeesFile(array $overrides = []): array
    {
        $a = $this->makeEmployee('waiting');
        $b = $this->makeEmployee('applicant');
        $rows = [];
        foreach ([[$a, 'MarwaFertility', '120', 2], [$b, 'TransitHotel', '90', 3]] as [$e, $company, $salary, $row]) {
            $rows[] = array_replace([$row, $e->id, $e->employee_number, WorkbookPlanExecutor::nameKey($e->full_name), $e->status->value, $company, $salary, '2026-10-01'], $overrides[$row] ?? []);
        }

        return [$this->csv('employees.csv', ['source_row', 'employee_id', 'employee_number', 'name_key', 'expected_status', 'company', 'salary', 'start_date'], $rows), [$a, $b]];
    }

    private function counts(): array
    {
        return [ClientCompany::query()->count(), EmployeeWorkAssignment::query()->count(), Employee::query()->where('status', 'active')->count()];
    }

    public function test_the_default_is_a_dry_run_that_previews_every_step_and_writes_nothing(): void
    {
        [$employees] = $this->employeesFile();
        $before = $this->counts();

        $this->artisan('hr:apply-workbook-plan', ['--companies' => $this->companiesFile(), '--employees' => $employees])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('COMPANIES: 3 record(s) - would_create: 3')
            ->expectsOutputToContain('STATUS: 2 record(s) - requires_override: 1, would_activate: 1')
            // The applicant cannot become Active without the override, so the preview shows its assignment as blocked too.
            ->expectsOutputToContain('ASSIGNMENTS: 2 record(s) - failed: 1, would_assign: 1')
            ->assertSuccessful();

        $this->assertSame($before, $this->counts());
        $this->assertDatabaseCount('client_company_aliases', 0);
    }

    public function test_a_dry_run_with_an_override_reason_previews_the_applicant_as_an_override_and_writes_nothing(): void
    {
        [$employees] = $this->employeesFile();
        $before = $this->counts();

        $this->artisan('hr:apply-workbook-plan', ['--companies' => $this->companiesFile(), '--employees' => $employees, '--override-reason' => self::OVERRIDE])
            ->expectsOutputToContain('STATUS: 2 record(s) - would_activate: 1, would_activate_with_override: 1')
            ->assertSuccessful();

        $this->assertSame($before, $this->counts());
    }

    public function test_commit_requires_a_super_admin(): void
    {
        [$employees] = $this->employeesFile();

        $this->artisan('hr:apply-workbook-plan', ['--companies' => $this->companiesFile(), '--employees' => $employees, '--commit' => true])
            ->expectsOutputToContain('--commit needs --admin-email')->assertFailed();

        $plain = Admin::factory()->create(['role' => AdminRole::Sales]);
        $this->artisan('hr:apply-workbook-plan', ['--companies' => $this->companiesFile(), '--commit' => true, '--admin-email' => $plain->email])->assertFailed();
        $this->assertSame(0, ClientCompany::query()->count());
    }

    public function test_the_source_label_names_the_workbook_in_history_notes_and_aliases(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        [$employees, [$a]] = $this->employeesFile();

        $this->artisan('hr:apply-workbook-plan', [
            '--companies' => $this->companiesFile(), '--employees' => $employees, '--commit' => true,
            '--admin-email' => $admin->email, '--joining-date' => '2026-10-01', '--override-reason' => self::OVERRIDE,
            '--source-label' => 'EMPLOYEE AND COMPANY PART TWO.xlsx',
        ])->assertSuccessful();

        $assignment = EmployeeWorkAssignment::query()->where('employee_id', $a->id)->firstOrFail();
        $this->assertSame('Assigned from the approved company workbook, EMPLOYEE AND COMPANY PART TWO.xlsx row 2.', $assignment->notes);
        $this->assertSame(['EMPLOYEE AND COMPANY PART TWO.xlsx'], ClientCompanyAlias::query()->distinct()->pluck('source')->all());
        $this->assertSame(0, ClientCompanyAlias::query()->where('source', 'EMPLOYEE AND COMPANY.xlsx')->count());
    }

    public function test_commit_registers_companies_with_aliases_activates_and_assigns_without_a_location(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        [$employees, [$a, $b]] = $this->employeesFile();

        $this->artisan('hr:apply-workbook-plan', [
            '--companies' => $this->companiesFile(), '--employees' => $employees, '--commit' => true,
            '--admin-email' => $admin->email, '--joining-date' => '2026-10-01', '--override-reason' => self::OVERRIDE,
        ])->assertSuccessful();

        $this->assertSame(['GURI TEST', 'MarwaFertility', 'TransitHotel'], ClientCompany::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame('guri', ClientCompany::query()->where('name', 'GURI TEST')->firstOrFail()->category->value);

        $marwa = ClientCompany::query()->where('name', 'MarwaFertility')->firstOrFail();
        $aliases = ClientCompanyAlias::query()->where('client_company_id', $marwa->id)->orderBy('alias')->get();
        $this->assertSame(['MARWAFERTILITY', 'MarwaFertility'], $aliases->pluck('alias')->all());
        $this->assertSame('rows 174,175', $aliases->first()->source_reference);

        foreach ([$a, $b] as $e) {
            $this->assertSame('active', $e->refresh()->status->value);
            $this->assertSame('2026-10-01', $e->joining_date->toDateString());
        }

        $assignment = EmployeeWorkAssignment::query()->where('employee_id', $a->id)->firstOrFail();
        $this->assertSame($marwa->id, $assignment->client_company_id);
        $this->assertNull($assignment->work_location_id, 'No workplace is invented.');
        $this->assertSame('120.00', (string) $assignment->salary_amount);
        $this->assertSame('USD', $assignment->salary_currency);
        $this->assertSame('monthly', $assignment->salary_frequency->value);
        $this->assertSame('2026-10-01', $assignment->start_date->toDateString());
        $this->assertSame(1, $assignment->salaryHistories()->count());
        $this->assertDatabaseCount('work_locations', 1);
    }

    public function test_running_it_again_creates_nothing_twice(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        [$employees] = $this->employeesFile();
        $args = ['--companies' => $this->companiesFile(), '--employees' => $employees, '--commit' => true, '--admin-email' => $admin->email, '--override-reason' => self::OVERRIDE];

        $this->artisan('hr:apply-workbook-plan', $args)->assertSuccessful();
        $after = $this->counts();
        $historyRows = EmployeeStatusHistory::query()->count();

        $this->artisan('hr:apply-workbook-plan', $args)
            ->expectsOutputToContain('exists: 3')->expectsOutputToContain('already_active: 2')->expectsOutputToContain('already_assigned: 2')
            ->assertSuccessful();

        $this->assertSame($after, $this->counts());
        $this->assertSame($historyRows, EmployeeStatusHistory::query()->count());
        $this->assertDatabaseCount('client_company_aliases', 5);
    }

    public function test_a_wrong_identity_or_missing_salary_fails_only_that_record(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        [$employees, [$a, $b]] = $this->employeesFile([2 => [3 => 'wrongnamekey'], 3 => [6 => '']]);
        $report = $this->dir.DIRECTORY_SEPARATOR.'report.csv';

        $this->artisan('hr:apply-workbook-plan', [
            '--companies' => $this->companiesFile(), '--employees' => $employees, '--commit' => true,
            '--admin-email' => $admin->email, '--report' => $report, '--override-reason' => self::OVERRIDE,
        ])->expectsOutputToContain('failed - IDENTITY_MISMATCH: 1')->expectsOutputToContain('failed - SALARY_OR_START_MISSING: 1')->assertFailed();

        $this->assertSame(0, EmployeeWorkAssignment::query()->count());
        $this->assertSame('active', $a->refresh()->status->value, 'The status step still ran for both approved people.');
        $this->assertStringContainsString('IDENTITY_MISMATCH', file_get_contents($report));
    }

    public function test_an_assignment_is_refused_while_the_company_is_not_registered(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        [$employees] = $this->employeesFile();

        $this->artisan('hr:apply-workbook-plan', ['--employees' => $employees, '--step' => ['status', 'assignments'], '--commit' => true, '--admin-email' => $admin->email, '--override-reason' => self::OVERRIDE])
            ->expectsOutputToContain('failed - COMPANY_NOT_REGISTERED: 2')->assertFailed();
        $this->assertSame(0, EmployeeWorkAssignment::query()->count());
    }

    public function test_bad_plan_files_are_rejected_before_anything_runs(): void
    {
        $bad = $this->csv('bad.csv', ['name', 'category', 'aliases'], [['X Co', 'business', '']]);
        $this->artisan('hr:apply-workbook-plan', ['--companies' => $bad])->expectsOutputToContain("category must be 'company' or 'guri'")->assertFailed();

        $dup = $this->csv('dup.csv', ['name', 'category', 'aliases'], [['TransitHotel', 'company', ''], ['TRANSITHOTEL', 'company', '']]);
        $this->artisan('hr:apply-workbook-plan', ['--companies' => $dup])->expectsOutputToContain('more than once')->assertFailed();

        $e = $this->csv('e.csv', ['source_row', 'employee_id', 'employee_number', 'name_key', 'expected_status', 'company', 'salary', 'start_date'],
            [[2, 5, 'EMP-1', 'a', 'waiting', 'X', '1', '2026-10-01'], [3, 5, 'EMP-1', 'a', 'waiting', 'X', '1', '2026-10-01']]);
        $this->artisan('hr:apply-workbook-plan', ['--employees' => $e])->expectsOutputToContain('same employee_id more than once')->assertFailed();

        $this->artisan('hr:apply-workbook-plan', ['--companies' => $this->dir.'/missing.csv'])->expectsOutputToContain('not found')->assertFailed();
        $this->assertSame(0, ClientCompany::query()->count());
    }

    public function test_a_case_variant_of_an_existing_company_is_not_registered_again(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->makeCompany('transithotel');

        $this->artisan('hr:apply-workbook-plan', ['--companies' => $this->companiesFile(), '--commit' => true, '--admin-email' => $admin->email])
            ->expectsOutputToContain('created: 2, exists: 1')->assertSuccessful();

        $this->assertSame(3, ClientCompany::query()->count());
    }

    // ------------------------------------------------------------- payments step (payment number vs registration phone)

    /** @return array{0: string, 1: Employee, 2: Employee, 3: Admin} */
    private function paymentFile(string $numberA, string $numberB = ''): array
    {
        $a = $this->makeEmployee('active', 'permanent', '615000111');
        $b = $this->makeEmployee('active', 'permanent', '615000222');
        $rows = [
            [2, $a->id, $a->employee_number, WorkbookPlanExecutor::nameKey($a->full_name), 'active', 'X', '100', '2026-10-01', $numberA],
            [3, $b->id, $b->employee_number, WorkbookPlanExecutor::nameKey($b->full_name), 'active', 'X', '100', '2026-10-01', $numberB],
        ];
        $file = $this->csv('payments.csv', ['source_row', 'employee_id', 'employee_number', 'name_key', 'expected_status', 'company', 'salary', 'start_date', 'payment_account_number'], $rows);

        return [$file, $a, $b, Admin::factory()->create(['role' => AdminRole::SuperAdmin])];
    }

    public function test_the_payments_step_records_the_payment_number_and_never_touches_the_registration_phone(): void
    {
        [$file, $a, $b, $admin] = $this->paymentFile('38628356');

        $this->artisan('hr:apply-workbook-plan', ['--employees' => $file, '--step' => ['payments'], '--commit' => true, '--admin-email' => $admin->email])->assertSuccessful();

        $this->assertSame('38628356', $a->fresh()->payment_account_number);
        $this->assertSame('615000111', $a->fresh()->phone, 'the registration phone is never overwritten');
        $this->assertNull($b->fresh()->payment_account_number, 'a row without a number is skipped, not guessed');
        $this->assertSame('615000222', $b->fresh()->phone);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => Employee::class, 'entity_id' => $a->id, 'action' => 'update']);
    }

    public function test_the_payments_step_is_a_dry_run_by_default_and_idempotent(): void
    {
        [$file, $a, , $admin] = $this->paymentFile('38628356');

        $this->artisan('hr:apply-workbook-plan', ['--employees' => $file, '--step' => ['payments']])->expectsOutputToContain('would_set: 1')->assertSuccessful();
        $this->assertNull($a->fresh()->payment_account_number, 'a dry run writes nothing');

        $this->artisan('hr:apply-workbook-plan', ['--employees' => $file, '--step' => ['payments'], '--commit' => true, '--admin-email' => $admin->email])->assertSuccessful();
        $this->artisan('hr:apply-workbook-plan', ['--employees' => $file, '--step' => ['payments'], '--commit' => true, '--admin-email' => $admin->email])->expectsOutputToContain('already_set: 1')->assertSuccessful();
        $this->assertSame(1, AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $a->id)->where('action', 'update')->count());
    }

    public function test_a_different_existing_payment_number_is_a_conflict_and_is_not_overwritten(): void
    {
        [$file, $a, , $admin] = $this->paymentFile('38628356');
        $a->update(['payment_account_number' => '61999999']);

        $this->artisan('hr:apply-workbook-plan', ['--employees' => $file, '--step' => ['payments'], '--commit' => true, '--admin-email' => $admin->email])->expectsOutputToContain('PAYMENT_NUMBER_CONFLICT')->assertFailed();

        $this->assertSame('61999999', $a->fresh()->payment_account_number);
    }

    public function test_an_identity_mismatch_or_an_invalid_number_writes_nothing(): void
    {
        [$file, $a, , $admin] = $this->paymentFile('<script>1');
        $this->artisan('hr:apply-workbook-plan', ['--employees' => $file, '--step' => ['payments'], '--commit' => true, '--admin-email' => $admin->email])->expectsOutputToContain('INVALID_PAYMENT_NUMBER')->assertFailed();
        $this->assertNull($a->fresh()->payment_account_number);

        $a->update(['full_name' => 'Someone Else Entirely']);
        [$file2] = [$this->csv('p2.csv', ['source_row', 'employee_id', 'employee_number', 'name_key', 'expected_status', 'company', 'salary', 'start_date', 'payment_account_number'], [[2, $a->id, $a->employee_number, 'originalname', 'active', 'X', '100', '2026-10-01', '38628356']])];
        $this->artisan('hr:apply-workbook-plan', ['--employees' => $file2, '--step' => ['payments'], '--commit' => true, '--admin-email' => $admin->email])->expectsOutputToContain('IDENTITY_MISMATCH')->assertFailed();
        $this->assertNull($a->fresh()->payment_account_number);
    }
}
