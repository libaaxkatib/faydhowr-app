<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correction and recovery records (owner decisions 2026-11-04). Four NEW, empty, additive tables - nothing existing is changed, copied or backfilled:
 *
 *  - payroll_section_reopenings        one row each time a FINALIZED company payroll is reopened: who, when, why, what it held (totals, money already paid)
 *                                      and, once it is finalized again, what it holds now. The finalized figures are kept here; nothing is overwritten.
 *  - payroll_workplace_reconciliations append-only entries that settle an OVERPAID workplace (paid more than the corrected payroll says): returned,
 *                                      carried forward or otherwise settled, with a reason. Like workplace payments, an entry is voided, never deleted.
 *  - company_closures                  one row per closing of a company / facility (effective date, reason, who) and, if it was closed by mistake, its reopening.
 *  - company_closure_assignments       exactly which assignments a closure ended / cancelled, so a reopening restores only those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_section_reopenings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->restrictOnDelete();
            $table->foreignId('payroll_run_section_id')->constrained('payroll_run_sections')->restrictOnDelete();
            $table->string('period', 7);
            $table->foreignId('client_company_id')->nullable()->constrained('client_companies')->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('reopened_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('reopened_by_role', 40)->nullable();
            $table->timestampTz('reopened_at');
            $table->timestampTz('previous_finalized_at')->nullable();
            $table->foreignId('previous_finalized_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->json('totals_before')->nullable();
            $table->bigInteger('paid_cents_at_reopen')->default(0);
            $table->unsignedInteger('payment_count_at_reopen')->default(0);
            $table->timestampTz('refinalized_at')->nullable();
            $table->foreignId('refinalized_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->json('totals_after')->nullable();
            $table->timestamps();

            $table->index(['payroll_run_section_id', 'reopened_at'], 'psr_section_index');
            $table->index(['period', 'client_company_id'], 'psr_period_company_index');
        });

        Schema::create('payroll_workplace_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->restrictOnDelete();
            $table->string('period', 7);
            $table->foreignId('client_company_id')->nullable()->constrained('client_companies')->restrictOnDelete();
            $table->foreignId('work_location_id')->nullable()->constrained('work_locations')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->string('method', 30);
            $table->text('reason');
            $table->date('settled_on');
            $table->string('idempotency_key', 64);
            $table->foreignId('recorded_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('recorded_by_role', 40)->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'idempotency_key']);
            $table->index(['payroll_run_id', 'client_company_id', 'work_location_id'], 'pwr_run_workplace_index');
        });

        Schema::create('company_closures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_company_id')->constrained('client_companies')->restrictOnDelete();
            $table->date('effective_date');
            $table->text('reason');
            $table->string('status', 20)->default('closed');
            $table->foreignId('closed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('closed_by_role', 40)->nullable();
            $table->string('previous_company_status', 20)->nullable();
            $table->json('summary')->nullable();
            $table->timestampTz('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('reopen_reason')->nullable();
            $table->json('reopen_summary')->nullable();
            $table->timestamps();

            $table->index(['client_company_id', 'status'], 'company_closures_company_status_index');
        });

        Schema::create('company_closure_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_closure_id')->constrained('company_closures')->cascadeOnDelete();
            $table->foreignId('work_assignment_id')->constrained('employee_work_assignments')->restrictOnDelete();
            $table->string('action', 20);
            $table->string('previous_status', 20);
            $table->date('previous_end_date')->nullable();
            $table->date('new_end_date')->nullable();
            $table->timestampTz('restored_at')->nullable();
            $table->foreignId('restored_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('restore_note', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_closure_id', 'work_assignment_id'], 'cca_closure_assignment_unique');
            $table->index('work_assignment_id', 'cca_assignment_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payroll_workplace_reconciliations ADD CONSTRAINT payroll_workplace_reconciliations_amount_positive CHECK (amount_cents > 0)');
            DB::statement('ALTER TABLE payroll_workplace_reconciliations ADD CONSTRAINT payroll_workplace_reconciliations_workplace_named CHECK (client_company_id IS NOT NULL OR work_location_id IS NOT NULL)');
            // A company has at most ONE open closure at a time (it may be closed, reopened and closed again later).
            DB::statement("CREATE UNIQUE INDEX company_closures_one_open_per_company ON company_closures (client_company_id) WHERE status = 'closed'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_closure_assignments');
        Schema::dropIfExists('company_closures');
        Schema::dropIfExists('payroll_workplace_reconciliations');
        Schema::dropIfExists('payroll_section_reopenings');
    }
};
