<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 1 (docs/HRM_MARKETING_SRS.md HR §9): Training Batch/Session -
 * "Need Training" is automatic eligibility, batch assignment is always a
 * deliberate HR action (see training_batch_participants).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('batch_number', 20)->unique();
            $table->date('batch_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
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
                'ALTER TABLE training_batches ADD CONSTRAINT training_batches_status_check '
                ."CHECK (status IN ('scheduled', 'completed', 'cancelled'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('training_batches');
    }
};
