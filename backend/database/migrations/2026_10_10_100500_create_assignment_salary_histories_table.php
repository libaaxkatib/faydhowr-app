<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Effective-dated salary history per work assignment. A row is the salary that
 * applies from `effective_from` until the next row's effective_from (so gaps
 * and overlaps are impossible by construction). The assignment's own
 * salary_amount/currency/frequency columns remain as the current value and are
 * kept in sync by ChangeAssignmentSalaryAction. Existing assignments are
 * backfilled with one row dated at their start_date, so no salary is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_salary_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_assignment_id')->constrained('employee_work_assignments')->restrictOnDelete();
            $table->date('effective_from');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->string('frequency', 20);
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['work_assignment_id', 'effective_from']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE assignment_salary_histories ADD CONSTRAINT assignment_salary_histories_frequency_check '
                ."CHECK (frequency IN ('monthly', 'weekly', 'daily'))",
            );
            DB::statement('ALTER TABLE assignment_salary_histories ADD CONSTRAINT assignment_salary_histories_amount_check CHECK (amount >= 0)');
        }

        DB::statement(
            'INSERT INTO assignment_salary_histories '
            .'(work_assignment_id, effective_from, amount, currency, frequency, reason, changed_by, created_at, updated_at) '
            .'SELECT id, start_date, salary_amount, salary_currency, salary_frequency, '
            ."'Initial salary (backfilled from the assignment)', created_by, created_at, updated_at "
            .'FROM employee_work_assignments',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_salary_histories');
    }
};
