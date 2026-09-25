<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 7 (Security/Hardening audit, P7-16): additive index only, no
 * behavior change. GetHrReportsSummaryAction's penalty-totals breakdown runs
 * an unscoped whereBetween() range scan on this column with no supporting
 * index today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_penalties', function (Blueprint $table): void {
            $table->index('penalty_date');
        });
    }

    public function down(): void
    {
        Schema::table('employee_penalties', function (Blueprint $table): void {
            $table->dropIndex(['penalty_date']);
        });
    }
};
