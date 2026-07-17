<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tracks every settings change for compliance and traceability. Values of
     * sensitive keys are masked before being written to this table.
     */
    public function up(): void
    {
        Schema::create('settings_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('category', 50);
            $table->string('key', 100);
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->foreignId('changed_by')->constrained('admins')->cascadeOnDelete();
            $table->timestamp('changed_at');
            $table->string('ip_address', 45)->nullable();

            $table->index(['category', 'key']);
            $table->index(['changed_by']);
            $table->index(['changed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings_audit_logs');
    }
};
