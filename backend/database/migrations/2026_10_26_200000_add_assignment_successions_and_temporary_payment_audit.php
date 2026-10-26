<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent/Temporary rules (docs/HR_PAYROLL_RULES.md N1, N2, N3, N5, N10). Additive: one new table and three nullable columns; nothing existing is
 * rewritten, backfilled or dropped.
 *
 *  - assignment_successions                   the replacement HR/Management chose when a PERMANENT assignment ended: who, of which kind (a Temporary
 *                                             employee who covered the position, or a person from the HR Waiting List), when it takes effect, the
 *                                             employment type before and after, the salary the new Permanent assignment inherited or was given, and
 *                                             the new assignment. One row per departing assignment (UNIQUE), so an assignment can never be "removed"
 *                                             twice with two different successors. Rows are history and are never deleted.
 *  - temporary_replacement_payments           calculated_amount (what the system expected when the payment was recorded), override_reason (why the
 *                                             entered amount differs) and calculation (the breakdown it used) beside the existing amount, notes and paid_by.
 *  - temporary_replacements.planned_end_date  the end date that was planned before the cover was ended early; end_date keeps meaning "the last day
 *                                             that actually counts".
 *
 * Safe to roll back only while these hold no data worth keeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_successions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('departing_assignment_id')->constrained('employee_work_assignments')->restrictOnDelete();
            $table->string('successor_type', 20);
            $table->foreignId('successor_employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('temporary_replacement_id')->nullable()->constrained('temporary_replacements')->nullOnDelete();
            $table->foreignId('successor_assignment_id')->nullable()->constrained('employee_work_assignments')->nullOnDelete();
            $table->date('departing_last_day');
            $table->date('successor_start_date');
            $table->string('employment_type_from', 20)->nullable();
            $table->string('employment_type_to', 20)->nullable();
            $table->decimal('salary_amount', 12, 2)->nullable();
            $table->string('salary_currency', 3)->nullable();
            $table->string('salary_frequency', 20)->nullable();
            $table->string('salary_source', 20)->nullable();
            $table->string('trigger', 30);
            $table->foreignId('chosen_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique('departing_assignment_id');
            $table->index('successor_employee_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE assignment_successions ADD CONSTRAINT assignment_successions_type_check CHECK (successor_type IN ('temporary', 'waiting_list'))");
            DB::statement('ALTER TABLE assignment_successions ADD CONSTRAINT assignment_successions_start_after_last_day_check CHECK (successor_start_date > departing_last_day)');
        }

        Schema::table('temporary_replacement_payments', function (Blueprint $table): void {
            $table->decimal('calculated_amount', 10, 2)->nullable()->after('amount');
            $table->text('override_reason')->nullable()->after('notes');
            $table->json('calculation')->nullable()->after('override_reason');
        });

        Schema::table('temporary_replacements', function (Blueprint $table): void {
            $table->date('planned_end_date')->nullable()->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('temporary_replacements', function (Blueprint $table): void {
            $table->dropColumn('planned_end_date');
        });

        Schema::table('temporary_replacement_payments', function (Blueprint $table): void {
            $table->dropColumn(['calculated_amount', 'override_reason', 'calculation']);
        });

        Schema::dropIfExists('assignment_successions');
    }
};
