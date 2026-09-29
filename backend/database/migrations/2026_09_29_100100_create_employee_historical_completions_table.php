<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #12: historical evidence/context only, NEVER live operational workflow
 * state. Approved rule: every Green (∪ Waiting List, deduplicated) employee
 * historically completed all three of Training/Practical/Uniform — this table
 * records that fact for display/reporting. It must never be read by, or write
 * to, status/pipeline_stage/employee_uniforms/employee_practical_assessments/
 * training_batch_participants/employee_contracts/employee_guarantors — see
 * the Issue #12 audit (GREEN=247, WAITING LIST=1177, OVERLAP=108, UNION=1316,
 * 1316*3=3948 expected rows) for the full business-rule writeup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_historical_completions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('stage', 20);
            $table->string('source', 30)->default('excel_migration');
            $table->string('source_reference', 255)->nullable();
            $table->text('source_notes')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'stage']);
            $table->index('stage');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE employee_historical_completions ADD CONSTRAINT employee_historical_completions_stage_check '
                ."CHECK (stage IN ('training', 'practical', 'uniform'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_historical_completions');
    }
};
