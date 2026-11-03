<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments recorded for a whole WORKPLACE (a Company or Guri, or one of its work locations) for one payroll month. Until now a payroll payment could
 * only be recorded one employee line at a time and always in full, so HR could not pay a workplace in part, nor see what was still owed per workplace.
 *
 * This is one new, empty, append-only table. Nothing existing is changed or copied: payroll_items, employee_payments and the payroll runs stay exactly as
 * they are, and a workplace's total / paid / outstanding / status are calculated from the stored payroll lines plus this table (never stored twice).
 * A row is never edited or deleted; a mistaken payment is voided with a reason (voided_at / void_reason) and then no longer counts. The idempotency key
 * turns a retry or a double click into a no-op. On PostgreSQL the amount must be positive and a row must name a company or a work location.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_workplace_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->restrictOnDelete();
            $table->string('period', 7);
            $table->foreignId('client_company_id')->nullable()->constrained('client_companies')->restrictOnDelete();
            $table->foreignId('work_location_id')->nullable()->constrained('work_locations')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->date('payment_date');
            $table->string('note', 500)->nullable();
            $table->string('idempotency_key', 64);
            $table->foreignId('recorded_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'idempotency_key']);
            $table->index(['payroll_run_id', 'client_company_id', 'work_location_id'], 'pwp_run_workplace_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payroll_workplace_payments ADD CONSTRAINT payroll_workplace_payments_amount_positive CHECK (amount_cents > 0)');
            DB::statement('ALTER TABLE payroll_workplace_payments ADD CONSTRAINT payroll_workplace_payments_workplace_named CHECK (client_company_id IS NOT NULL OR work_location_id IS NOT NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_workplace_payments');
    }
};
