<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 1 (docs/HRM_MARKETING_SRS.md HR §5): gender becomes a captured
 * field (Male/Female only, per business decision) and pipeline_stage tracks
 * the new pre-Waiting pipeline (Damiin -> Contract -> Uniform -> Training ->
 * Practical) independently of the existing `status` enum, which keeps its
 * current meaning untouched (see the Phase 1 plan's architecture note).
 * Both columns are nullable so existing rows are never broken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('gender', 10)->nullable()->after('reference_name');
            $table->string('pipeline_stage', 30)->nullable()->after('status');
            $table->index('pipeline_stage');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE employees ADD CONSTRAINT employees_gender_check CHECK (gender IN ('male', 'female'))",
            );
            DB::statement(
                'ALTER TABLE employees ADD CONSTRAINT employees_pipeline_stage_check '
                ."CHECK (pipeline_stage IN ('damiin_needed', 'contract_pending', 'uniform_pending', "
                ."'need_training', 'need_practical', 'practical_repeat', 'rejected'))",
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_gender_check');
            DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_pipeline_stage_check');
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex(['pipeline_stage']);
            $table->dropColumn(['gender', 'pipeline_stage']);
        });
    }
};
