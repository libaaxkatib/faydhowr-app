<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approved decision: a work location must not be mandatory for a company
 * assignment. The company is now recorded directly on the assignment
 * (client_company_id) and the specific work location is optional, filled in
 * whenever it becomes known. Existing assignments are backfilled from their
 * location's company so nothing existing loses its company. An assignment
 * must always identify a company OR a location (an office assignment has a
 * location and no company).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_work_assignments', function (Blueprint $table): void {
            $table->foreignId('client_company_id')->nullable()->after('work_location_id')
                ->constrained('client_companies')->restrictOnDelete();
            $table->index(['client_company_id', 'status']);
        });

        Schema::table('employee_work_assignments', function (Blueprint $table): void {
            $table->unsignedBigInteger('work_location_id')->nullable()->change();
        });

        DB::statement(
            'UPDATE employee_work_assignments SET client_company_id = ('
            .'SELECT work_locations.client_company_id FROM work_locations '
            .'WHERE work_locations.id = employee_work_assignments.work_location_id)',
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE employee_work_assignments ADD CONSTRAINT employee_work_assignments_company_or_location_check '
                .'CHECK (work_location_id IS NOT NULL OR client_company_id IS NOT NULL)',
            );
        }
    }

    public function down(): void
    {
        if (DB::table('employee_work_assignments')->whereNull('work_location_id')->exists()) {
            throw new RuntimeException(
                'Cannot roll back: some assignments have no work location. Assign locations first.',
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employee_work_assignments DROP CONSTRAINT IF EXISTS employee_work_assignments_company_or_location_check');
        }

        Schema::table('employee_work_assignments', function (Blueprint $table): void {
            $table->unsignedBigInteger('work_location_id')->nullable(false)->change();
        });

        Schema::table('employee_work_assignments', function (Blueprint $table): void {
            $table->dropIndex(['client_company_id', 'status']);
            $table->dropConstrainedForeignId('client_company_id');
        });
    }
};
