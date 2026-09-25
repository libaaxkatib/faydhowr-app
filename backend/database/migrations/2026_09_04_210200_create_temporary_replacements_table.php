<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 3 (Workforce Operations): a replacement employee temporarily
 * covering another employee's existing work assignment. The replaced
 * employee and their location/company are reached via work_assignment,
 * never denormalized here - one source of truth, same choice Phase 2 made
 * for workforce_requests. "Multi-company replacement work" needs no extra
 * field: replacement_employee_id is any Active employee, independent of
 * which company they normally work for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporary_replacements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_assignment_id')->constrained('employee_work_assignments')->restrictOnDelete();
            $table->foreignId('replacement_employee_id')->constrained('employees')->restrictOnDelete();

            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('daily_rate', 10, 2);
            $table->string('currency', 3);
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['work_assignment_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE temporary_replacements ADD CONSTRAINT temporary_replacements_status_check '
                ."CHECK (status IN ('active', 'ended'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_replacements');
    }
};
