<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision N-5 (Option A, 2026-11-05): an OPTIONAL payment reference on a company payment (bank transaction id, mobile-money transaction id, receipt
 * number, ...), next to the existing note. Additive: one nullable column; the existing payment rows keep NULL and nothing is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_workplace_payments', function (Blueprint $table): void {
            $table->string('reference', 100)->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_workplace_payments', function (Blueprint $table): void {
            $table->dropColumn('reference');
        });
    }
};
