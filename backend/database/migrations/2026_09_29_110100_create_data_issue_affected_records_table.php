<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a Data Issue to the actual record(s) it affects — polymorphic so the
 * same table serves Employees today and other record types (Bookings,
 * Customers, Quotations, ...) later without a new table per module.
 * context_note is the issue-specific detail for THIS link (e.g. "missing
 * phone", "duplicate of EMP-000701") — everything else about the record
 * (name, category, status, ...) is read live from the record itself, never
 * duplicated here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_issue_affected_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('data_issue_id')->constrained('data_issues')->cascadeOnDelete();
            $table->string('recordable_type', 60);
            $table->unsignedBigInteger('recordable_id');
            $table->text('context_note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['data_issue_id', 'recordable_type', 'recordable_id'], 'data_issue_affected_records_unique');
            $table->index(['recordable_type', 'recordable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_issue_affected_records');
    }
};
