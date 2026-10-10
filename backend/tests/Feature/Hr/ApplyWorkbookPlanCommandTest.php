<?php

namespace Tests\Feature\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
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
            ->expectsOutputToContain('STATUS: 2 record(s) - would_activate: 2')
            ->expectsOutputToContain('ASSIGNMENTS: 2 record(s) - would_assign: 2')
            ->assertSuccessful();

        $this->assertSame($before, $this->counts());
        $this->assertDatabaseCount('client_company_aliases', 0);
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

    public function test_commit_registers_companies_with_aliases_activates_and_assigns_without_a_location(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        [$employees, [$a, $b]] = $this->employeesFile();

        $this->artisan('hr:apply-workbook-plan', [
            '--companies' => $this->companiesFile(), '--employees' => $employees, '--commit' => true,
            '--admin-email' => $admin->email, '--joining-date' => '2026-10-01',
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
        $args = ['--companies' => $this->companiesFile(), '--employees' => $employees, '--commit' => true, '--admin-email' => $admin->email];

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
            '--admin-email' => $admin->email, '--report' => $report,
        ])->expectsOutputToContain('failed - IDENTITY_MISMATCH: 1')->expectsOutputToContain('failed - SALARY_OR_START_MISSING: 1')->assertFailed();

        $this->assertSame(0, EmployeeWorkAssignment::query()->count());
        $this->assertSame('active', $a->refresh()->status->value, 'The status step still ran for both approved people.');
        $this->assertStringContainsString('IDENTITY_MISMATCH', file_get_contents($report));
    }

    public function test_an_assignment_is_refused_while_the_company_is_not_registered(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        [$employees] = $this->employeesFile();

        $this->artisan('hr:apply-workbook-plan', ['--employees' => $employees, '--step' => ['status', 'assignments'], '--commit' => true, '--admin-email' => $admin->email])
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
}
