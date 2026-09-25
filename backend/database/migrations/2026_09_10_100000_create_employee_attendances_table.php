<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 4 (Fayadhowr Office Staff): unlike every other ledger table in
 * this app, a single day is one fact, not a history of events - marking the
 * same employee+date again corrects it in place (updateOrCreate) rather than
 * creating a superseding row. The unique constraint enforces that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->date('date');
            $table->string('status', 20);
            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE employee_attendances ADD CONSTRAINT employee_attendances_status_check '
                ."CHECK (status IN ('present', 'absent', 'late'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_attendances');
    }
};
