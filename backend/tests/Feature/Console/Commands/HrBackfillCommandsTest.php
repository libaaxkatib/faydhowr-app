<?php

namespace Tests\Feature\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeHistoricalCompletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Safety-behavior tests for the secondary-contact and historical-completion
 * backfill commands: dry-run does not write, --commit writes exactly the
 * expected values, and existing values are never overwritten.
 */
class HrBackfillCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(array $overrides = []): Employee
    {
        $category = EmployeeCategory::query()->where('name', 'General Cleaning')->sole();

        return Employee::query()->create(array_merge([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name(),
            'phone' => fake()->unique()->numerify('6########'),
            'employee_category_id' => $category->id,
            'status' => 'applicant',
        ], $overrides));
    }

    public function test_secondary_contact_backfill_dry_run_does_not_write(): void
    {
        $employee = $this->makeEmployee([
            'notes' => 'Secondary/emergency contact: Hooyo Muna 616605866',
        ]);

        $this->artisan('hr:backfill-secondary-contact')->assertExitCode(0);

        $employee->refresh();
        self::assertNull($employee->secondary_contact_name);
        self::assertNull($employee->secondary_contact_phone);
    }

    public function test_secondary_contact_backfill_commit_writes_expected_values(): void
    {
        $employee = $this->makeEmployee([
            'notes' => 'Secondary/emergency contact: Hooyo Muna 616605866',
        ]);

        $this->artisan('hr:backfill-secondary-contact', ['--commit' => true])->assertExitCode(0);

        $employee->refresh();
        self::assertSame('Hooyo Muna', $employee->secondary_contact_name);
        self::assertSame('616605866', $employee->secondary_contact_phone);
    }

    public function test_secondary_contact_backfill_never_overwrites_an_existing_value(): void
    {
        $employee = $this->makeEmployee([
            'notes' => 'Secondary/emergency contact: Hooyo Muna 616605866',
            'secondary_contact_name' => 'Already Set',
        ]);

        $this->artisan('hr:backfill-secondary-contact', ['--commit' => true])->assertExitCode(0);

        $employee->refresh();
        self::assertSame('Already Set', $employee->secondary_contact_name);
    }

    public function test_historical_completions_backfill_dry_run_writes_nothing(): void
    {
        $employee = $this->makeEmployee(['notes' => 'hore shaqo loo geeyay']);

        $this->artisan('hr:backfill-historical-completions')->assertExitCode(0);

        self::assertSame(0, EmployeeHistoricalCompletion::query()->count());
    }

    public function test_historical_completions_backfill_commit_writes_three_rows(): void
    {
        $employee = $this->makeEmployee(['notes' => 'hore shaqo loo geeyay']);

        $this->artisan('hr:backfill-historical-completions', ['--commit' => true])->assertExitCode(0);

        self::assertSame(3, EmployeeHistoricalCompletion::query()->where('employee_id', $employee->id)->count());
    }
}
