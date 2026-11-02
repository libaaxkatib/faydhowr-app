<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super Admin Recovery Center: one row per APPLIED correction. It is the correction history (who, when, why, before/after, the payroll impact that was shown)
 * and the duplicate-execution guard: `idempotency_key` is unique, so the same request can never be applied twice. Nothing existing is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recovery_corrections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('idempotency_key', 80)->unique();
            $table->string('workflow', 40);
            $table->string('month', 7)->nullable();
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('actor_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('reason');
            $table->string('plan_hash', 64);
            $table->json('params')->nullable();
            $table->json('changes')->nullable();
            $table->json('impact')->nullable();
            $table->string('status', 20)->default('applied');
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('workflow');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_corrections');
    }
};
