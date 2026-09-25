<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 6: additive index only, no behavior change - supports the new
 * company-wide Performance Report's date-range filtering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_performance_reviews', function (Blueprint $table): void {
            $table->index('review_date');
        });
    }

    public function down(): void
    {
        Schema::table('employee_performance_reviews', function (Blueprint $table): void {
            $table->dropIndex(['review_date']);
        });
    }
};
