<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 1 (docs/HRM_MARKETING_SRS.md HR §8): Uniform is a hard-gating
 * stage - a person cannot be called to Training until this record's status
 * is 'confirmed'. One row per employee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_uniforms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->date('purchased_at')->nullable();
            $table->date('received_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE employee_uniforms ADD CONSTRAINT employee_uniforms_status_check '
                ."CHECK (status IN ('pending', 'purchased', 'received', 'confirmed'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_uniforms');
    }
};
