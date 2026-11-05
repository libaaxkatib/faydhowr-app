<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Release 1A (owner decisions N-2 / N-2a / N-2b / D-3): payroll is generated ONE COMPANY at a time, so the "when and by whom was this company generated"
 * stamp and the warnings of that generation belong to the company's section, not to the month's run. Additive only: three nullable columns, no backfill
 * (a section generated before this keeps NULL and readers fall back to the run's own stamp), no existing value is rewritten.
 *
 * The foreign key to admins is added with a plain ALTER TABLE on PostgreSQL only: on SQLite adding a constraint makes Laravel REBUILD the table, which would
 * recreate the partial "one no-company section per run" index without its WHERE clause (and break every multi-company month in the SQLite test suite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_run_sections', function (Blueprint $table): void {
            $table->timestampTz('generated_at')->nullable()->after('totals');
            $table->unsignedBigInteger('generated_by')->nullable()->after('generated_at');
            // The Temporary Replacement / calculation warnings found when THIS company was generated (never blocking; shown at Generate, Review and Finalize).
            $table->json('generation_warnings')->nullable()->after('generated_by');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payroll_run_sections ADD CONSTRAINT payroll_run_sections_generated_by_foreign FOREIGN KEY (generated_by) REFERENCES admins (id) ON DELETE SET NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payroll_run_sections DROP CONSTRAINT IF EXISTS payroll_run_sections_generated_by_foreign');
        }

        Schema::table('payroll_run_sections', function (Blueprint $table): void {
            $table->dropColumn(['generated_at', 'generated_by', 'generation_warnings']);
        });
    }
};
