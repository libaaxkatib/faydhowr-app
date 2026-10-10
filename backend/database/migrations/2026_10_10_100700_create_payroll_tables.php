<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stored payroll lifecycle. One run per month (unique period) moves
 * draft -> reviewed -> approved -> finalized. Each item is one employee
 * assignment in that run (unique per run), with every calculation input kept
 * in `calc_snapshot` so a finalized month can always be explained and
 * reproduced. All money is stored in integer cents. Deductions are itemized
 * and each source (a replacement for a month, a penalty, an advance...) can
 * be deducted only once for a period, enforced by a unique index. A finalized
 * run is immutable: corrections and reversals are recorded as separate
 * adjustments that apply in a later period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('period', 7)->unique();
            $table->string('status', 20)->default('draft');
            $table->string('currency', 3)->default('USD');
            $table->timestampTz('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampTz('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->json('totals')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->restrictOnDelete();
            $table->string('period', 7);
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('work_assignment_id')->constrained('employee_work_assignments')->restrictOnDelete();
            $table->string('employee_name', 150);
            $table->string('employee_number', 20);
            $table->string('company_name', 150)->nullable();
            $table->string('workplace_name', 150)->nullable();
            $table->unsignedSmallInteger('eligible_days');
            $table->unsignedSmallInteger('days_in_month');
            $table->bigInteger('gross_cents');
            $table->bigInteger('replacement_deduction_cents')->default(0);
            $table->bigInteger('other_deduction_cents')->default(0);
            $table->bigInteger('adjustment_cents')->default(0);
            $table->bigInteger('net_cents');
            $table->json('calc_snapshot');
            $table->string('payment_status', 20)->default('unpaid');
            $table->timestampTz('paid_at')->nullable();
            $table->unsignedBigInteger('employee_payment_id')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'work_assignment_id']);
            $table->index(['employee_id', 'period']);
        });

        Schema::create('payroll_item_deductions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_item_id')->constrained('payroll_items')->cascadeOnDelete();
            $table->string('period', 7);
            $table->string('kind', 30);
            $table->string('source_type', 60);
            $table->unsignedBigInteger('source_id');
            $table->bigInteger('amount_cents');
            $table->string('description', 255);
            $table->json('detail')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'source_type', 'source_id', 'period']);
            $table->index('payroll_item_id');
        });

        Schema::create('payroll_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_item_id')->constrained('payroll_items')->restrictOnDelete();
            $table->string('kind', 20);
            $table->bigInteger('amount_cents');
            $table->text('reason');
            $table->string('applies_in_period', 7);
            $table->string('status', 20)->default('pending');
            $table->foreignId('applied_in_item_id')->nullable()->constrained('payroll_items')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['applies_in_period', 'status']);
        });

        Schema::table('employee_payments', function (Blueprint $table): void {
            $table->foreignId('payroll_item_id')->nullable()->after('employee_id')->constrained('payroll_items')->restrictOnDelete();
            $table->unique('payroll_item_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payroll_runs ADD CONSTRAINT payroll_runs_status_check CHECK (status IN ('draft', 'reviewed', 'approved', 'finalized'))");
            DB::statement("ALTER TABLE payroll_items ADD CONSTRAINT payroll_items_payment_status_check CHECK (payment_status IN ('unpaid', 'paid'))");
            DB::statement("ALTER TABLE payroll_adjustments ADD CONSTRAINT payroll_adjustments_kind_check CHECK (kind IN ('correction', 'reversal'))");
            DB::statement("ALTER TABLE payroll_adjustments ADD CONSTRAINT payroll_adjustments_status_check CHECK (status IN ('pending', 'applied'))");
            DB::statement("ALTER TABLE payroll_item_deductions ADD CONSTRAINT payroll_item_deductions_kind_check CHECK (kind IN ('replacement', 'penalty', 'advance', 'absence', 'adjustment', 'unpaid_leave'))");
        }
    }

    public function down(): void
    {
        Schema::table('employee_payments', function (Blueprint $table): void {
            $table->dropUnique(['payroll_item_id']);
            $table->dropConstrainedForeignId('payroll_item_id');
        });

        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('payroll_item_deductions');
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_runs');
    }
};
