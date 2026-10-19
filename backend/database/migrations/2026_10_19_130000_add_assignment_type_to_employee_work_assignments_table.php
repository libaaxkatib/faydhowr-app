<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes "Permanent" and "Replacement" an explicit property of a work assignment. Additive: every existing assignment becomes
 * 'permanent' (the value it effectively has today); nothing is inferred from replaces_assignment_id, no row is otherwise changed,
 * and no payroll figure depends on the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_work_assignments', function (Blueprint $table): void {
            $table->string('assignment_type', 20)->default('permanent')->after('position_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE employee_work_assignments ADD CONSTRAINT employee_work_assignments_type_check CHECK (assignment_type IN ('permanent', 'replacement'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employee_work_assignments DROP CONSTRAINT IF EXISTS employee_work_assignments_type_check');
        }

        Schema::table('employee_work_assignments', function (Blueprint $table): void {
            $table->dropColumn('assignment_type');
        });
    }
};
