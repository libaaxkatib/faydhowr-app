<?php

namespace Tests\Feature\Hr;

use App\Models\Employee;
use App\Models\EmployeeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The migration that adds the "Office Staff" employee category is data-only, idempotent and never touches
 * an existing category or employee.
 */
class OfficeStaffCategoryMigrationTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_14_100000_add_office_staff_employee_category.php');
    }

    /** @return array<string, int> name => id */
    private function categories(): array
    {
        return DB::table('employee_categories')->orderBy('id')->pluck('id', 'name')->all();
    }

    public function test_a_fresh_database_gets_exactly_one_office_staff_category_and_the_original_five_are_untouched(): void
    {
        $categories = $this->categories();

        $this->assertCount(6, $categories);
        $this->assertSame(['General Cleaning', 'Cooking', 'Waiter', 'Barista', 'Home Team', 'Office Staff'], array_keys($categories));
        $this->assertSame([1, 2, 3, 4, 5], array_slice(array_values($categories), 0, 5), 'the original categories keep their ids');
        $this->assertSame(1, DB::table('employee_categories')->where('name', 'Office Staff')->count());
    }

    public function test_running_it_again_creates_no_duplicate_and_changes_nothing(): void
    {
        $before = DB::table('employee_categories')->orderBy('id')->get()->all();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertEquals($before, DB::table('employee_categories')->orderBy('id')->get()->all());
    }

    public function test_an_existing_category_with_that_name_in_any_letter_case_is_reused_not_duplicated(): void
    {
        DB::table('employee_categories')->where('name', 'Office Staff')->update(['name' => 'office staff', 'description' => 'kept as is']);

        $this->migration()->up();

        $this->assertSame(1, DB::table('employee_categories')->whereRaw('LOWER(name) = ?', ['office staff'])->count());
        $this->assertSame('kept as is', DB::table('employee_categories')->where('name', 'office staff')->value('description'));
        $this->assertSame('office staff', EmployeeCategory::officeStaff()->name, 'registration finds it case-insensitively too');
    }

    public function test_when_the_category_is_missing_it_is_added_once_without_touching_employees(): void
    {
        $employee = $this->makeEmployee();
        $original = $employee->employee_category_id;
        DB::table('employee_categories')->where('name', 'Office Staff')->delete();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(1, DB::table('employee_categories')->where('name', 'Office Staff')->count());
        $this->assertSame($original, Employee::query()->findOrFail($employee->id)->employee_category_id);
        $this->assertSame(['General Cleaning', 'Cooking', 'Waiter', 'Barista', 'Home Team', 'Office Staff'], array_keys($this->categories()));
    }

    public function test_rolling_back_removes_it_only_while_nobody_uses_it(): void
    {
        $this->migration()->down();
        $this->assertDatabaseMissing('employee_categories', ['name' => 'Office Staff']);

        $this->migration()->up();
        $employee = $this->makeEmployee();
        $employee->forceFill(['employee_category_id' => EmployeeCategory::officeStaff()->id])->save();

        $this->migration()->down();

        $this->assertDatabaseHas('employee_categories', ['name' => 'Office Staff']);
        $this->assertSame(EmployeeCategory::officeStaff()->id, $employee->refresh()->employee_category_id, 'an in-use category is left in place, nobody is detached');
    }
}
