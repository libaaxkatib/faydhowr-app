<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 6: additive index only, no behavior change - supports the new
 * company-wide Leave Report's date-range filtering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_leaves', function (Blueprint $table): void {
            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::table('employee_leaves', function (Blueprint $table): void {
            $table->dropIndex(['start_date', 'end_date']);
        });
    }
};
