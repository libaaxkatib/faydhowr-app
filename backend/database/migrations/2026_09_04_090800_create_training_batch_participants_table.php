<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A real model rather than a bare pivot, since `result`/`notes` are
 * meaningful business columns - matches how employee_practical_assessments
 * is already its own table rather than a pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_batch_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_batch_id')->constrained('training_batches')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('result', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['training_batch_id', 'employee_id']);
            $table->index('employee_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE training_batch_participants ADD CONSTRAINT training_batch_participants_result_check '
                ."CHECK (result IN ('pending', 'completed', 'absent'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('training_batch_participants');
    }
};
