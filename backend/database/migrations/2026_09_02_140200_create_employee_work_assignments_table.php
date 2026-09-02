<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The historical, per-employee work-assignment record. Whether an assignment
 * is "client company" or "Fayadhowr office" is read off the joined
 * work_location.location_type, never duplicated as a second column here.
 * No DB rule limits an employee to one active assignment — concurrent active
 * assignments at different locations are valid by design. No destroy route
 * exists for this table anywhere in the app; assignments are only ever
 * created or ended (end_date + status=ended), so history is never lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_work_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('work_location_id')->constrained('work_locations')->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();

            $table->date('start_date');
            $table->date('end_date')->nullable();

            $table->decimal('salary_amount', 10, 2);
            $table->string('salary_currency', 3);
            $table->string('salary_frequency', 20);

            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['work_location_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE employee_work_assignments ADD CONSTRAINT employee_work_assignments_salary_frequency_check '
                ."CHECK (salary_frequency IN ('monthly', 'weekly', 'daily'))",
            );
            DB::statement(
                'ALTER TABLE employee_work_assignments ADD CONSTRAINT employee_work_assignments_status_check '
                ."CHECK (status IN ('active', 'ended', 'cancelled'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_work_assignments');
    }
};
