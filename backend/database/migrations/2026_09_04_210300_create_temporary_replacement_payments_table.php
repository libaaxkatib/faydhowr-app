<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 3: a manual payment ledger for temporary replacements - a row's
 * existence IS the paid record (no pending/paid state machine). HR logs each
 * payment as they actually make it. The full calendar-day payroll
 * calculation engine is explicitly Phase 5's job, not this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporary_replacement_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('temporary_replacement_id')->constrained('temporary_replacements')->cascadeOnDelete();

            $table->date('payment_date');
            $table->decimal('amount', 10, 2);
            $table->text('notes')->nullable();

            $table->foreignId('paid_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_replacement_payments');
    }
};
