<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR Release 1B freshness guard: a fingerprint of the penalties / advances a company's draft was generated from. Review and Finalize refuse a section whose
 * inputs changed since (DeductionInputsFingerprint). Nullable: a section generated before this release has no fingerprint and is judged as before, so the
 * 112 locked November sections are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_run_sections', function (Blueprint $table): void {
            $table->string('deduction_inputs_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_sections', function (Blueprint $table): void {
            $table->dropColumn('deduction_inputs_hash');
        });
    }
};
