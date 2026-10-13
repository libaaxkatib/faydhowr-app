<?php

use App\Models\EmployeeCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the "Office Staff" employee category, used automatically when someone is registered as Office Staff.
 *
 * Data only: no table or column changes, and the same "seed reference data in a migration" pattern the other five
 * categories use. It is safe to run more than once: nothing is inserted if a category with that name already
 * exists (compared case-insensitively, so an existing "office staff" is reused instead of getting a near-duplicate),
 * and the insert itself ignores a unique-name conflict. It never renames, merges or re-points any existing category
 * or employee, and it never changes an employee's category.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('employee_categories')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(EmployeeCategory::OFFICE_STAFF)])
            ->exists();

        if ($exists) {
            return;
        }

        $now = now();

        DB::table('employee_categories')->insertOrIgnore([
            'name' => EmployeeCategory::OFFICE_STAFF,
            'description' => 'Employees of the Fayadhowr office. Assigned automatically when someone is registered as Office Staff.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Removes the category only while no employee uses it; an in-use category is left in place rather than failing
     * the rollback or detaching anyone.
     */
    public function down(): void
    {
        $id = DB::table('employee_categories')->where('name', EmployeeCategory::OFFICE_STAFF)->value('id');

        if ($id !== null && ! DB::table('employees')->where('employee_category_id', $id)->exists()) {
            DB::table('employee_categories')->where('id', $id)->delete();
        }
    }
};
