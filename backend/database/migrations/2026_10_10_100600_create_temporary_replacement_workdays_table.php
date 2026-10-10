<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verified workday ledger for Temporary replacements. A replacement is charged
 * only for days recorded here and verified; unverified or voided days never
 * cost anything. Two partial unique indexes make double charging impossible at
 * the database level:
 *   - one live (non-void) row per permanent assignment per date, so two
 *     replacements can never both charge the same covered day;
 *   - one live row per Temporary employee per date, so one person cannot be
 *     recorded in two places on the same day.
 * Voided rows stay as history with who/when/why. `daily_rate` on the
 * replacement becomes optional/legacy: the rate is derived from the Permanent
 * employee's salary, never typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temporary_replacements', function (Blueprint $table): void {
            $table->timestampTz('approved_at')->nullable()->after('status');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('admins')->nullOnDelete();
        });

        Schema::table('temporary_replacements', function (Blueprint $table): void {
            $table->decimal('daily_rate', 10, 2)->nullable()->change();
            $table->string('currency', 3)->default('USD')->change();
        });

        Schema::create('temporary_replacement_workdays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('temporary_replacement_id')->constrained('temporary_replacements')->restrictOnDelete();
            $table->foreignId('work_assignment_id')->constrained('employee_work_assignments')->restrictOnDelete();
            $table->foreignId('replacement_employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('work_date');
            $table->string('status', 20)->default('recorded');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['temporary_replacement_id', 'work_date']);
            $table->index(['work_assignment_id', 'work_date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE temporary_replacement_workdays ADD CONSTRAINT temporary_replacement_workdays_status_check '
                ."CHECK (status IN ('recorded', 'verified', 'void'))",
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX trw_assignment_date_live_unique ON temporary_replacement_workdays (work_assignment_id, work_date) '
            ."WHERE status <> 'void'",
        );
        DB::statement(
            'CREATE UNIQUE INDEX trw_employee_date_live_unique ON temporary_replacement_workdays (replacement_employee_id, work_date) '
            ."WHERE status <> 'void'",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_replacement_workdays');

        Schema::table('temporary_replacements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
    }
};
