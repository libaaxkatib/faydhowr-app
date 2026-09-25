<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 6 (Dashboard & Reporting): additive index only, no behavior
 * change - Phase 6 introduces the first real date-range queries against
 * this column (Financial Ledger, month-to-date dashboard tiles).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_payments', function (Blueprint $table): void {
            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::table('employee_payments', function (Blueprint $table): void {
            $table->dropIndex(['payment_date']);
        });
    }
};
