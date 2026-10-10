<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent / Temporary classification. Every existing employee defaults to
 * `permanent` (no existing record is reclassified); Temporary employees are
 * those who cover a Permanent employee's position through the replacement
 * workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('employment_type', 20)->default('permanent')->after('status');
            $table->index('employment_type');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE employees ADD CONSTRAINT employees_employment_type_check '
                ."CHECK (employment_type IN ('permanent', 'temporary'))",
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_employment_type_check');
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex(['employment_type']);
            $table->dropColumn('employment_type');
        });
    }
};
