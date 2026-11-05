<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freshness guard, source data (owner approval of 2026-11-05): a compact snapshot of the assignments, salary rows, Temporary covers and absence windows a
 * company's payroll draft was generated from (PayrollSourceInputs). Review and Finalize compare it with the data as it is now and refuse a draft that went
 * stale, saying WHAT changed. Nullable and additive: a section generated before this release has no snapshot and is judged as before, so the locked
 * sections of earlier months (the 112 locked November sections included) are never touched and nothing existing is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_run_sections', function (Blueprint $table): void {
            $table->json('generation_inputs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_sections', function (Blueprint $table): void {
            $table->dropColumn('generation_inputs');
        });
    }
};
