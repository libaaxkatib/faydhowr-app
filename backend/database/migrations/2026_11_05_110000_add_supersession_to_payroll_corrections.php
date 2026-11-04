<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1 (owner decision 2026-11-04): when a company's payroll for a month is reopened (finalized or approved -> draft) and rebuilt, the OPEN payroll corrections
 * of that company and month stop being valid - the rebuilt lines already contain what the correction was recording, so keeping it would count the same pay
 * twice. Such a correction is SUPERSEDED (status `superseded`), never deleted: who, when, why and by which section reopening are recorded here.
 * Additive only: four nullable columns on payroll_corrections. `status` is a plain string column (no CHECK), so no constraint changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_corrections', function (Blueprint $table): void {
            $table->timestampTz('superseded_at')->nullable();
            $table->foreignId('superseded_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('supersede_reason', 500)->nullable();
            $table->foreignId('superseded_section_id')->nullable()->constrained('payroll_run_sections')->nullOnDelete();
            $table->index('superseded_section_id', 'payroll_corrections_superseded_section_index');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_corrections', function (Blueprint $table): void {
            $table->dropIndex('payroll_corrections_superseded_section_index');
            $table->dropConstrainedForeignId('superseded_section_id');
            $table->dropConstrainedForeignId('superseded_by');
            $table->dropColumn(['superseded_at', 'supersede_reason']);
        });
    }
};
