<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 5 (Payroll & Accounting): a manual penalty/deduction ledger - no
 * approval workflow, a row's existence IS the official penalty record. Never
 * updated in place, never deleted as part of normal editing - every penalty
 * is permanent history. payroll_period ('YYYY-MM') is the month the
 * deduction applies to, validated at the FormRequest layer, not a DB
 * constraint (no fixed value set). Kept entirely separate from
 * employee_payments/employee_advances/attendance/leave/performance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_penalties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->date('penalty_date');
            $table->text('reason');
            $table->decimal('deduction_amount', 10, 2);
            $table->string('currency', 3);
            $table->string('payroll_period', 7);
            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'payroll_period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_penalties');
    }
};
