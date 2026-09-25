<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 1 (docs/HRM_MARKETING_SRS.md HR §10): Practical Batch/Session,
 * sitting above the existing employee_practical_assessments table (which
 * stays the per-attempt record - see the migration that adds
 * practical_batch_id/attempt_number to it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practical_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('batch_number', 20)->unique();
            $table->date('batch_date');
            $table->string('team_or_group', 100)->nullable();
            $table->foreignId('trainer_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('location', 150)->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE practical_batches ADD CONSTRAINT practical_batches_status_check '
                ."CHECK (status IN ('scheduled', 'completed', 'cancelled'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('practical_batches');
    }
};
