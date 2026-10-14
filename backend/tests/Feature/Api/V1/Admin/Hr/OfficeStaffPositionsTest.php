<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Employee;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The nine Office Staff positions: seeded by an idempotent data migration into the existing positions table, never
 * duplicated, never carrying a salary, creatable from the registration flow, and always paired with a salary that a
 * person types in for that specific employee.
 */
class OfficeStaffPositionsTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const NINE = [
        'Administrative Officer', 'HR Officer', 'Marketing Officer', 'Accountant', 'Sales Officer',
        'Customer Service Officer', 'Secretary / Receptionist', 'Driver', 'Office Cleaner',
    ];

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->token] = $this->superAdminToken();
    }

    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_15_100000_add_office_staff_positions.php');
    }

    /** @return array<string, mixed> */
    private function staff(int $positionId, string $salary, string $name, string $phone): array
    {
        return [
            'full_name' => $name, 'phone' => $phone, 'position_id' => $positionId, 'monthly_salary' => $salary, 'start_date' => '2026-10-01',
            'emergency_contact_name' => 'Emergency Person', 'emergency_contact_phone' => '0615333444',
            'guarantor_name' => 'Guarantor Person', 'guarantor_phone' => '0615555666',
        ];
    }

    private function id(string $name): int
    {
        return (int) Position::query()->where('name', $name)->value('id');
    }

    // ---- the migration -----------------------------------------------------------------------------------

    public function test_a_fresh_database_has_exactly_the_nine_positions_each_once_with_no_department_and_no_salary(): void
    {
        $names = Position::query()->orderBy('name')->pluck('name')->all();
        $expected = self::NINE;
        sort($expected);

        $this->assertSame($expected, $names);
        $this->assertSame(0, Position::query()->whereNotNull('department_id')->count());
        $this->assertSame(
            ['created_at', 'department_id', 'description', 'id', 'name', 'updated_at'],
            collect(Schema::getColumnListing('positions'))->sort()->values()->all(),
            'a position has no salary, range or default amount - there is nowhere to store one',
        );
    }

    public function test_running_the_migration_again_adds_nothing(): void
    {
        $before = DB::table('positions')->orderBy('id')->get()->all();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertEquals($before, DB::table('positions')->orderBy('id')->get()->all());
    }

    public function test_existing_positions_are_reused_in_any_letter_case_or_spacing_and_unrelated_ones_are_untouched(): void
    {
        DB::table('positions')->delete();
        $kept = [
            'accountant' => Position::query()->create(['name' => 'accountant', 'description' => 'mine'])->id,
            'driver' => Position::query()->create(['name' => '  Driver  '])->id,
            'hr' => Position::query()->create(['name' => 'HR   OFFICER'])->id,
            'unrelated' => Position::query()->create(['name' => 'Warehouse Clerk', 'description' => 'unrelated'])->id,
        ];

        $this->migration()->up();

        $this->assertSame(9 + 1, Position::query()->count(), 'nine required names, one unrelated; nothing doubled');
        $this->assertSame(['accountant', '  Driver  ', 'HR   OFFICER', 'Warehouse Clerk'], Position::query()->whereIn('id', array_values($kept))->orderBy('id')->pluck('name')->all(), 'existing rows are not renamed');
        $this->assertSame('mine', Position::query()->find($kept['accountant'])->description);
        $this->assertSame('unrelated', Position::query()->find($kept['unrelated'])->description);
        foreach (['Administrative Officer', 'Marketing Officer', 'Sales Officer', 'Customer Service Officer', 'Secretary / Receptionist', 'Office Cleaner'] as $created) {
            $this->assertSame(1, Position::query()->where('name', $created)->count(), $created);
        }
    }

    public function test_rolling_back_removes_only_unused_positions_the_migration_created(): void
    {
        $manual = Position::query()->create(['name' => 'Warehouse Clerk']);
        $used = $this->id('Driver');
        $employee = $this->makeEmployee();
        $employee->forceFill(['position_id' => $used])->save();

        $this->migration()->down();

        $this->assertNotNull(Position::query()->find($used), 'a position in use is never removed');
        $this->assertNotNull(Position::query()->find($manual->id), 'a position created by hand is never removed');
        $this->assertSame(['Driver', 'Warehouse Clerk'], Position::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame($used, $employee->refresh()->position_id);
    }

    // ---- creating positions through the existing endpoint ------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function duplicateSpellings(): array
    {
        return ['exact' => ['Driver'], 'lower case' => ['driver'], 'upper case' => ['DRIVER'], 'extra spaces' => ['  Driver  '], 'inner spaces' => ['Customer   Service  Officer'], 'slash name' => ['secretary / receptionist']];
    }

    #[DataProvider('duplicateSpellings')]
    public function test_a_duplicate_position_name_is_refused_however_it_is_typed(string $name): void
    {
        $before = Position::query()->count();

        $this->asToken($this->token)->postJson('/api/v1/admin/hr/positions', ['name' => $name])
            ->assertStatus(422)->assertJsonValidationErrors('name')->assertJsonPath('errors.name.0', 'A position with this name already exists.');

        $this->assertSame($before, Position::query()->count());
    }

    public function test_a_new_position_is_created_tidied_and_can_not_be_created_twice(): void
    {
        $created = $this->asToken($this->token)->postJson('/api/v1/admin/hr/positions', ['name' => '  Office   Manager '])->assertCreated();

        $this->assertSame('Office Manager', $created->json('data.name'));
        $this->asToken($this->token)->postJson('/api/v1/admin/hr/positions', ['name' => 'office manager'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->assertSame(1, Position::query()->where('name', 'Office Manager')->count());
        $this->assertSame(10, Position::query()->count());
    }

    public function test_a_blank_or_missing_name_is_refused(): void
    {
        foreach ([[], ['name' => ''], ['name' => '   ']] as $body) {
            $this->asToken($this->token)->postJson('/api/v1/admin/hr/positions', $body)->assertStatus(422)->assertJsonValidationErrors('name');
        }
    }

    public function test_renaming_a_position_cannot_collide_with_another_but_may_keep_or_recase_its_own_name(): void
    {
        $driver = $this->id('Driver');

        $this->asToken($this->token)->putJson("/api/v1/admin/hr/positions/{$driver}", ['name' => 'accountant'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->asToken($this->token)->putJson("/api/v1/admin/hr/positions/{$driver}", ['name' => 'Driver'])->assertOk();
        $this->asToken($this->token)->putJson("/api/v1/admin/hr/positions/{$driver}", ['name' => 'DRIVER'])->assertOk()->assertJsonPath('data.name', 'DRIVER');
        $this->asToken($this->token)->putJson("/api/v1/admin/hr/positions/{$driver}", ['name' => ''])->assertStatus(422);
        $this->assertSame(9, Position::query()->count());
    }

    public function test_creating_a_position_needs_hr_manage_and_viewing_the_list_needs_hr_view(): void
    {
        $this->asToken($this->tokenWithOnly(['hr.view']))->postJson('/api/v1/admin/hr/positions', ['name' => 'Night Guard'])->assertForbidden();
        $this->asToken($this->noPermissionToken())->postJson('/api/v1/admin/hr/positions', ['name' => 'Night Guard'])->assertForbidden();
        $this->asToken($this->tokenWithOnly(['hr.manage']))->postJson('/api/v1/admin/hr/positions', ['name' => 'Night Guard'])->assertCreated();

        $list = $this->asToken($this->tokenWithOnly(['hr.view']))->getJson('/api/v1/admin/hr/positions')->assertOk()->json('data');
        $this->assertCount(10, $list);
        foreach (self::NINE as $name) {
            $this->assertContains($name, array_column($list, 'name'));
        }
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson('/api/v1/admin/hr/positions')->assertUnauthorized();
    }

    // ---- Office Staff registration with each position, salary always typed -------------------------------

    public function test_every_one_of_the_nine_positions_can_be_used_to_register_office_staff_with_a_manually_entered_salary(): void
    {
        $salaries = ['120', '150.50', '200', '310', '95.25', '180', '140', '75', '60'];

        foreach (self::NINE as $i => $name) {
            $this->asToken($this->token)->postJson('/api/v1/admin/hr/office-staff', $this->staff($this->id($name), $salaries[$i], "Person {$i}", '061570000'.$i))
                ->assertCreated()->assertJsonPath('data.position_name', $name)->assertJsonPath('data.current_salary.amount', number_format((float) $salaries[$i], 2, '.', ''));
        }

        $stored = DB::table('employee_work_assignments')->orderBy('id')->pluck('salary_amount', 'position_id')->map(fn ($v) => (float) $v)->all();
        foreach (self::NINE as $i => $name) {
            $this->assertEquals((float) $salaries[$i], $stored[$this->id($name)], "{$name}: the salary is exactly what was typed");
        }
        $this->assertSame(9, Employee::query()->count());
    }

    public function test_the_same_position_carries_different_salaries_and_changing_position_never_changes_the_salary(): void
    {
        $driver = $this->id('Driver');

        $a = $this->asToken($this->token)->postJson('/api/v1/admin/hr/office-staff', $this->staff($driver, '150', 'Driver One', '0615700001'))->assertCreated()->json('data');
        $b = $this->asToken($this->token)->postJson('/api/v1/admin/hr/office-staff', $this->staff($driver, '900', 'Driver Two', '0615700002'))->assertCreated()->json('data');

        $this->assertSame(['150.00', '900.00'], [$a['current_salary']['amount'], $b['current_salary']['amount']]);
        $this->assertDatabaseMissing('positions', ['name' => 'Driver', 'description' => '150']);

        $this->asToken($this->token)->putJson('/api/v1/admin/hr/work-assignments/'.DB::table('employee_work_assignments')->where('employee_id', $a['id'])->value('id'), ['position_id' => $this->id('Accountant')])->assertOk();
        $this->assertEquals(150.0, (float) DB::table('employee_work_assignments')->where('employee_id', $a['id'])->value('salary_amount'), 'moving someone to another position leaves their salary alone');
    }

    public function test_a_salary_must_always_be_entered_and_no_position_supplies_one(): void
    {
        foreach (self::NINE as $name) {
            $payload = $this->staff($this->id($name), '100', 'No Salary Person', '0615711111');
            unset($payload['monthly_salary']);

            $this->asToken($this->token)->postJson('/api/v1/admin/hr/office-staff', $payload)->assertStatus(422)->assertJsonValidationErrors('monthly_salary');
        }
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('employee_work_assignments', 0);
    }

    public function test_an_existing_employee_added_with_a_position_must_also_get_a_typed_salary_and_keeps_their_category(): void
    {
        $employee = $this->makeEmployee();
        $category = $employee->employee_category_id;
        $payload = $this->staff($this->id('Office Cleaner'), '88', 'ignored', '0');
        $payload = ['employee_id' => $employee->id] + array_diff_key($payload, array_flip(['full_name', 'phone']));
        $withoutSalary = $payload;
        unset($withoutSalary['monthly_salary']);

        $this->asToken($this->token)->postJson('/api/v1/admin/hr/office-staff/assign', $withoutSalary)->assertStatus(422)->assertJsonValidationErrors('monthly_salary');
        $this->asToken($this->token)->postJson('/api/v1/admin/hr/office-staff/assign', $payload)->assertOk()
            ->assertJsonPath('data.position_name', 'Office Cleaner')->assertJsonPath('data.current_salary.amount', '88.00');

        $this->assertSame($category, $employee->refresh()->employee_category_id);
    }

    // ---- salary can change later through the existing workflow, and payroll follows it -------------------

    public function test_salary_is_edited_later_through_the_existing_assignment_workflow_and_payroll_follows_the_recorded_salary(): void
    {
        $created = $this->asToken($this->token)->postJson('/api/v1/admin/hr/office-staff', $this->staff($this->id('Accountant'), '300', 'Raise Person', '0615722222') + [])->assertCreated()->json('data');
        $assignmentId = DB::table('employee_work_assignments')->where('employee_id', $created['id'])->value('id');
        // started in September so the whole of October is a clean mix of old and new salary
        DB::table('employee_work_assignments')->where('id', $assignmentId)->update(['start_date' => '2026-09-01']);
        DB::table('assignment_salary_histories')->where('work_assignment_id', $assignmentId)->update(['effective_from' => '2026-09-01']);

        $before = $this->asToken($this->token)->getJson('/api/v1/admin/hr/reports/payroll-rollup?period=2026-10')->assertOk()->json('meta.totals.net_payable');
        $this->assertSame('300.00', $before);

        // 15 days at 300 + 16 days at 400, over 31 days
        $this->asToken($this->token)->putJson("/api/v1/admin/hr/work-assignments/{$assignmentId}", [
            'salary_amount' => 400, 'salary_effective_from' => '2026-10-16', 'salary_change_reason' => 'Raise',
        ])->assertOk();

        $this->assertSame(2, DB::table('assignment_salary_histories')->where('work_assignment_id', $assignmentId)->count(), 'the old salary stays in the history');
        $this->assertSame('351.61', $this->asToken($this->token)->getJson('/api/v1/admin/hr/reports/payroll-rollup?period=2026-10')->json('meta.totals.net_payable'));
        $this->assertDatabaseCount('payroll_runs', 0);
        $this->assertDatabaseCount('employee_payments', 0);
    }

    // ---- creating a position, then using it straight away -------------------------------------------------

    public function test_a_position_created_on_the_spot_can_be_used_immediately_and_then_is_a_duplicate(): void
    {
        $new = $this->asToken($this->token)->postJson('/api/v1/admin/hr/positions', ['name' => 'Security Guard'])->assertCreated()->json('data.id');

        $this->asToken($this->token)->postJson('/api/v1/admin/hr/office-staff', $this->staff($new, '110', 'Guard One', '0615733333'))
            ->assertCreated()->assertJsonPath('data.position_name', 'Security Guard');

        $this->asToken($this->token)->postJson('/api/v1/admin/hr/positions', ['name' => 'security guard'])->assertStatus(422);
        $this->assertSame(1, Position::query()->where('name', 'Security Guard')->count());
    }
}
