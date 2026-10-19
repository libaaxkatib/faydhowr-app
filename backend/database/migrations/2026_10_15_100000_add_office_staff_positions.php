<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the nine Office Staff positions to the existing positions table (HR > Positions), so they are available in the
 * Office Staff registration form.
 *
 * Data only: no table or column changes, and NO salary of any kind - a position never carries a salary, range or default;
 * each employee's monthly salary is always entered by hand. It is safe to run more than once: a position is inserted only
 * when no position with that name exists (compared ignoring letter case and extra spaces), so existing positions are reused,
 * never duplicated, renamed, moved or deleted. Positions are created without a department.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const NAMES = [
        'Administrative Officer',
        'HR Officer',
        'Marketing Officer',
        'Accountant',
        'Sales Officer',
        'Customer Service Officer',
        'Secretary / Receptionist',
        'Driver',
        'Office Cleaner',
    ];

    /** Marks the rows this migration created, so a rollback never removes a position someone else created. */
    private const DESCRIPTION = 'Office Staff position.';

    public function up(): void
    {
        $now = now();
        // Same comparison the Positions screen uses: letter case and extra or doubled spaces are ignored.
        $key = fn (?string $value): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''));
        $existing = DB::table('positions')->pluck('name')->map($key)->all();

        foreach (self::NAMES as $name) {
            if (in_array($key($name), $existing, true)) {
                continue;
            }

            DB::table('positions')->insert([
                'department_id' => null,
                'name' => $name,
                'description' => self::DESCRIPTION,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Removes only the positions this migration created (marked by its description) and only while nothing uses them.
     * Anything else - including a same-named position someone created themselves - is left alone.
     */
    public function down(): void
    {
        DB::table('positions')
            ->whereIn('name', self::NAMES)
            ->where('description', self::DESCRIPTION)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('employees')->whereColumn('employees.position_id', 'positions.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('employee_work_assignments')->whereColumn('employee_work_assignments.position_id', 'positions.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('workforce_requests')->whereColumn('workforce_requests.position_id', 'positions.id'))
            ->delete();
    }
};
