<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Position;
use App\Models\WorkforceRequest;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The data-only migration that adds the nine Office Staff positions must leave every existing employee, assignment, salary,
 * workforce request, payroll and company row exactly as it was, whether it runs once or twice, and must not delete or
 * rename a position that is already in use.
 */
class PositionsMigrationDataSafetyTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    /** Tables whose rows must be byte-for-byte identical before and after. */
    private const PROTECTED_TABLES = [
        'employees', 'employee_work_assignments', 'assignment_salary_histories', 'workforce_requests',
        'client_companies', 'work_locations', 'payroll_runs', 'payroll_items', 'departments',
    ];

    /** @return array<string, string> table => fingerprint of every row */
    private function fingerprint(): array
    {
        $out = [];
        foreach (self::PROTECTED_TABLES as $table) {
            $out[$table] = md5(DB::table($table)->orderBy('id')->get()->toJson());
        }

        return $out;
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_15_100000_add_office_staff_positions.php');
    }

    public function test_running_the_migration_on_a_populated_database_changes_no_existing_business_data(): void
    {
        // Production-like starting point: the nine positions are not there yet, other positions are in use.
        DB::table('positions')->delete();
        $driver = Position::query()->create(['name' => 'Driver ', 'description' => 'created by HR']); // same name as one of the nine, different spacing
        $cook = Position::query()->create(['name' => 'Head Cook']);

        $company = $this->makeCompany('Fingerprint Co');
        $employee = $this->makeEmployee();
        $employee->update(['position_id' => $cook->id]);
        $assignment = $this->makeAssignment($employee, $company, null, 275);
        $assignment->update(['position_id' => $driver->id]);
        WorkforceRequest::query()->create([
            'work_location_id' => WorkLocation::query()->where('location_type', 'office')->firstOrFail()->id,
            'position_id' => $cook->id,
            'quantity_needed' => 2,
            'status' => 'open',
            'requested_date' => now()->toDateString(),
        ]);

        $before = $this->fingerprint();
        $salary = $assignment->fresh()->salary_amount;

        $migration = $this->migration();
        $migration->up();
        $afterFirst = $this->fingerprint();
        $migration->up(); // running it again is a no-op
        $afterSecond = $this->fingerprint();

        $this->assertSame($before, $afterFirst, 'No employee, assignment, salary, request, payroll or company row changed.');
        $this->assertSame($before, $afterSecond);
        $this->assertSame($salary, $assignment->fresh()->salary_amount);

        // Positions: the two existing ones are untouched; only the eight missing names were added (Driver already existed).
        $this->assertSame('Driver ', $driver->fresh()->name);
        $this->assertSame('created by HR', $driver->fresh()->description);
        $this->assertSame('Head Cook', $cook->fresh()->name);
        $this->assertSame(10, Position::query()->count());
        $this->assertSame(1, Position::query()->whereRaw('lower(trim(name)) = ?', ['driver'])->count());
    }

    public function test_rolling_back_never_removes_a_position_that_is_in_use_or_that_someone_else_created(): void
    {
        $migration = $this->migration();
        $inUse = Position::query()->where('name', 'Accountant')->firstOrFail();
        $employee = $this->makeEmployee();
        $employee->update(['position_id' => $inUse->id]);
        $humanMade = Position::query()->where('name', 'Driver')->firstOrFail();
        $humanMade->update(['description' => 'edited by HR']);
        $before = $this->fingerprint();

        $migration->down();

        $this->assertDatabaseHas('positions', ['id' => $inUse->id]);
        $this->assertDatabaseHas('positions', ['id' => $humanMade->id]);
        $this->assertSame($employee->position_id, $inUse->id);
        $this->assertSame($before, $this->fingerprint());
        $this->assertSame(2, Position::query()->count(), 'Only the unused, migration-created rows were removed.');
    }
}
