<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 4: every recorded leave period is permanent history, like a
 * contract - no approval-workflow state machine, recording a leave IS the
 * approved record (mirrors the Phase 3 payment-ledger philosophy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_leaves', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->string('leave_type', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE employee_leaves ADD CONSTRAINT employee_leaves_leave_type_check '
                ."CHECK (leave_type IN ('annual', 'sick', 'unpaid', 'other'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leaves');
    }
};
