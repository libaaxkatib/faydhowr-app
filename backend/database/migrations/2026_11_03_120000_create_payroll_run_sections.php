<?php

use App\Support\Hr\Payroll\PayrollSectionBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company-level payroll sections. A payroll month stays ONE run (payroll_runs, unique period); beneath it each company (client company / guri,
 * or "no company" for lines whose assignment names none) gets its own section with its own lifecycle - draft, reviewed, approved, finalized -
 * its own reviewer / approver / finalizer and dates, and its own totals. Each payroll line points at its section through the new nullable
 * payroll_items.section_id. A run's own status is derived from its sections (the least advanced one), so a month is approved or finalized only
 * when every company is.
 *
 * Additive only: one new table and one new nullable column; no existing column is dropped, renamed or changed. The existing lines of
 * EVERY run are LINKED to their company's section (PayrollSectionBackfill; a section starts with its run's own status and stamps): only payroll_items.section_id is written, nothing is recalculated or regenerated,
 * no amount, deduction, payment status or relationship changes, and it is safe to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_run_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->restrictOnDelete();
            // Null = the lines whose assignment names no company (for example the Fayadhowr office).
            $table->foreignId('client_company_id')->nullable()->constrained('client_companies')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampTz('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('admins')->nullOnDelete();
            // item_count, employee_count, gross_cents, replacement_deduction_cents, other_deduction_cents, adjustment_cents, net_cents
            $table->json('totals')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'client_company_id']);
            $table->index(['payroll_run_id', 'status']);
        });

        // A unique index treats NULLs as different: the "no company" section needs its own one-per-run guarantee.
        DB::statement('CREATE UNIQUE INDEX payroll_run_sections_run_no_company_unique ON payroll_run_sections (payroll_run_id) WHERE client_company_id IS NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payroll_run_sections ADD CONSTRAINT payroll_run_sections_status_check CHECK (status IN ('draft', 'reviewed', 'approved', 'finalized'))");
        }

        Schema::table('payroll_items', function (Blueprint $table): void {
            $table->foreignId('section_id')->nullable()->after('payroll_run_id')->constrained('payroll_run_sections')->restrictOnDelete();
            $table->index('section_id');
        });

        // Link the lines of every run. Nothing is recalculated; only section_id is written.
        PayrollSectionBackfill::run();
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('section_id');
        });

        Schema::dropIfExists('payroll_run_sections');
    }
};
