<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 4: a manual payment ledger, structurally identical to Phase 3's
 * temporary_replacement_payments - a row's existence IS the paid event, no
 * pending/paid state machine. Salary CALCULATION is explicitly Phase 5's job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->date('payment_date');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->text('notes')->nullable();

            $table->foreignId('paid_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_payments');
    }
};
