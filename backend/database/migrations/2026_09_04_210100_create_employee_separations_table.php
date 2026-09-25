<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 3 (Workforce Operations): one row per separation event, never
 * updated in place - a later rehire-then-separate-again cycle creates a new
 * row so full history is preserved, same principle used for contracts and
 * practical attempts in Phase 1. Not linked back to employee_status_histories
 * (both written in the same transaction by MarkEmployeeSeparatedAction and
 * correlatable by employee_id + timestamp if ever needed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_separations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->string('reason', 20);
            $table->date('separation_date');
            $table->boolean('rehire_eligible')->default(true);
            $table->text('notes')->nullable();

            $table->foreignId('separated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE employee_separations ADD CONSTRAINT employee_separations_reason_check '
                ."CHECK (reason IN ('resigned', 'terminated', 'contract_ended', 'other'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_separations');
    }
};
