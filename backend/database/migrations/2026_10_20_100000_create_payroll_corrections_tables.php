<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Controlled corrections of assignment/salary data that touches an approved or finalized payroll month (Super Admin only).
 *
 * `payroll_corrections` keeps what the closed month recorded for the employee, what it should have been, the difference, who
 * corrected it and why - the closed payroll run and its lines are never edited. An OVERPAYMENT is recovered from later salaries:
 * `payroll_recovery_deductions` is the ledger of those monthly deductions (one row per correction and month, enforced by a unique
 * index so a retry or recalculation can never deduct twice). Two new tables. On PostgreSQL the existing CHECK on the deduction kinds is
 * widened by one value ('overpayment_recovery'): the monthly recovery is stored as a deduction line of the payroll item, and without
 * this the database would refuse it. No existing row or value changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_corrections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_key');
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('work_assignment_id')->nullable()->constrained('employee_work_assignments')->nullOnDelete();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->restrictOnDelete();
            $table->foreignId('payroll_item_id')->nullable()->constrained('payroll_items')->nullOnDelete();
            $table->string('period', 7);
            $table->string('kind', 20); // overpayment | underpayment
            $table->string('action', 40); // edited | replaced | returned | stopped | salary_changed
            $table->bigInteger('recorded_net_cents');
            $table->bigInteger('corrected_net_cents');
            $table->bigInteger('difference_cents'); // recorded - corrected: > 0 overpaid, < 0 underpaid
            $table->bigInteger('outstanding_cents')->default(0); // overpayment still to recover
            $table->string('status', 24); // open | recovered | pending_policy
            $table->string('reason', 500);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['batch_key', 'employee_id', 'period']);
            $table->index(['employee_id', 'status']);
            $table->index('period');
        });

        Schema::create('payroll_recovery_deductions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_correction_id')->constrained('payroll_corrections')->restrictOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->string('period', 7);
            $table->bigInteger('amount_cents');
            $table->string('status', 16); // scheduled (draft/reviewed run) | applied (approved/finalized run)
            $table->timestamps();

            $table->unique(['payroll_correction_id', 'period']);
            $table->index(['period', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payroll_item_deductions DROP CONSTRAINT IF EXISTS payroll_item_deductions_kind_check');
            DB::statement("ALTER TABLE payroll_item_deductions ADD CONSTRAINT payroll_item_deductions_kind_check CHECK (kind IN ('replacement', 'penalty', 'advance', 'absence', 'adjustment', 'unpaid_leave', 'overpayment_recovery'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payroll_item_deductions DROP CONSTRAINT IF EXISTS payroll_item_deductions_kind_check');
            DB::statement("ALTER TABLE payroll_item_deductions ADD CONSTRAINT payroll_item_deductions_kind_check CHECK (kind IN ('replacement', 'penalty', 'advance', 'absence', 'adjustment', 'unpaid_leave'))");
        }

        Schema::dropIfExists('payroll_recovery_deductions');
        Schema::dropIfExists('payroll_corrections');
    }
};
