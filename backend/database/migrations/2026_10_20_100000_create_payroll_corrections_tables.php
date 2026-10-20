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
 * index so a retry or recalculation can never deduct twice), `payroll_correction_transactions` holds the payments/repayments made
 * outside a payroll run, and `payroll_payment_voids` records the voiding of a payroll "Mark as paid" payment. Four new tables. On PostgreSQL the existing CHECK on the deduction kinds is
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
            $table->string('status', 24); // open (still owed by / to the employee) | recovered (overpayment repaid) | written_off (debt cleared with a Super Admin write-off) | settled (underpayment paid)
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

        // Money that moves OUTSIDE a payroll run, always as its own traceable transaction: Super Admin paying an underpayment (also written to
        // the employee payment ledger), or Super Admin / HR Manager recording that an employee repaid part of a debt. A transaction is never
        // edited; a repayment recorded in error is voided (kept, with who/when/why). The idempotency key makes a retry a no-op.
        Schema::create('payroll_correction_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_correction_id')->constrained('payroll_corrections')->restrictOnDelete();
            $table->string('type', 40); // underpayment_payment | underpayment_payment_reversal | debt_repayment | debt_write_off
            $table->bigInteger('amount_cents');
            $table->date('transaction_date');
            $table->string('note', 500)->nullable();
            $table->string('reason', 500)->nullable(); // mandatory for a write-off and for a reversal
            // The debt/amount owed before and after this transaction, so the ledger can be read line by line.
            $table->bigInteger('balance_before_cents')->nullable();
            $table->bigInteger('balance_after_cents')->nullable();
            // A reversal is its own row linked to the payment it cancels; the unique index means a payment can be reversed only once.
            $table->foreignId('reverses_transaction_id')->nullable()->unique()->constrained('payroll_correction_transactions')->restrictOnDelete();
            $table->string('idempotency_key', 64);
            $table->foreignId('employee_payment_id')->nullable()->constrained('employee_payments')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['payroll_correction_id', 'idempotency_key']);
            $table->index(['payroll_correction_id', 'type']);
        });

        // The linked correction entry of a voided PAYROLL payment ("Mark as paid"): one row per voided payment, never edited. The original
        // employee payment is untouched; the reversal is a negative employee_payments row linked by reverses_payment_id.
        Schema::create('payroll_payment_voids', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_item_id')->constrained('payroll_items')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('original_payment_id')->unique()->constrained('employee_payments')->restrictOnDelete();
            $table->foreignId('reversal_payment_id')->unique()->constrained('employee_payments')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('reason', 500);
            $table->foreignId('voided_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['payroll_item_id']);
        });

        // A payment recorded for an underpayment can be voided by a Super Admin: the original payment row stays untouched and a linked row with
        // the negative amount is added, so every total that sums the payment ledger reconciles. One reversal per payment (unique).
        Schema::table('employee_payments', function (Blueprint $table): void {
            $table->foreignId('reverses_payment_id')->nullable()->unique()->after('payroll_item_id')->constrained('employee_payments')->restrictOnDelete();
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

        Schema::dropIfExists('payroll_payment_voids');

        Schema::table('employee_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reverses_payment_id');
        });

        Schema::dropIfExists('payroll_correction_transactions');
        Schema::dropIfExists('payroll_recovery_deductions');
        Schema::dropIfExists('payroll_corrections');
    }
};
