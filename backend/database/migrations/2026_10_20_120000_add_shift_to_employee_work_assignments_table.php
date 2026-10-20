<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Day / Night shift of a work assignment. Additive and backward compatible: one nullable column, no default and no backfill - an assignment
 * that never had a shift recorded stays NULL (unknown), so nothing is silently turned into "day" or "night". Code that does not know the
 * column keeps working (it neither reads nor writes it). The shift belongs to the assignment, not to the employee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_work_assignments', function (Blueprint $table): void {
            $table->string('shift', 10)->nullable()->after('assignment_type');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE employee_work_assignments ADD CONSTRAINT employee_work_assignments_shift_check CHECK (shift IS NULL OR shift IN ('day', 'night'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employee_work_assignments DROP CONSTRAINT IF EXISTS employee_work_assignments_shift_check');
        }

        Schema::table('employee_work_assignments', function (Blueprint $table): void {
            $table->dropColumn('shift');
        });
    }
};
