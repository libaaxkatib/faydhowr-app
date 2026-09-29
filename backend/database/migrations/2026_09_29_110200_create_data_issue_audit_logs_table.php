<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedicated, field-level audit trail for Data Issues — one row per changed
 * field per update (who / what field / old value / new value / when),
 * mirroring the existing SettingsAuditLog precedent rather than forcing
 * this into the generic single-description AuditLog. Never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_issue_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('data_issue_id')->constrained('data_issues')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('field_changed', 60);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('data_issue_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_issue_audit_logs');
    }
};
