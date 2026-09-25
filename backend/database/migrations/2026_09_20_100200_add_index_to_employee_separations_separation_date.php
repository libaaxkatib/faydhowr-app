<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 7 (Security/Hardening audit, P7-16): additive index only, no
 * behavior change. GetHrReportsSummaryAction's separation-reason breakdown
 * runs an unscoped whereBetween() range scan on this column with no
 * supporting index today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_separations', function (Blueprint $table): void {
            $table->index('separation_date');
        });
    }

    public function down(): void
    {
        Schema::table('employee_separations', function (Blueprint $table): void {
            $table->dropIndex(['separation_date']);
        });
    }
};
