<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 5: a manual salary-advance ledger - same philosophy as
 * employee_penalties (no approval workflow, permanent history, never
 * overwritten/deleted). Multiple advances per employee per month are
 * explicitly allowed. Kept entirely separate from employee_penalties/
 * employee_payments/attendance/leave/performance - its own table, its own
 * relation, its own resource, its own frontend card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_advances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->date('advance_date');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->string('payroll_period', 7);
            $table->text('reason');
            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'payroll_period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_advances');
    }
};
