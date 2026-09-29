<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data Issues & Reconciliation Center — the permanent, reusable place for
 * recording any data problem (missing/duplicate/conflicting/unexplained)
 * anywhere in the system, not just this HR migration. Creating an issue
 * NEVER modifies the data it describes; that stays a separate, deliberate
 * action. expected_value/actual_value/difference_value are free-text (a
 * discrepancy is often "1,316 employees / 3,948 rows", not a single number)
 * — kept as plain text columns per the explicit "don't over-JSON this"
 * requirement, not a JSON blob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_issues', function (Blueprint $table): void {
            $table->id();
            $table->string('issue_number', 20)->unique();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('module', 30);
            $table->string('issue_type', 30);
            $table->string('severity', 20);
            $table->string('status', 30)->default('open');

            $table->text('expected_value')->nullable();
            $table->text('actual_value')->nullable();
            $table->text('difference_value')->nullable();

            $table->text('root_cause')->nullable();
            $table->text('resolution')->nullable();
            $table->text('source_reference')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->timestampTz('resolved_at')->nullable();

            $table->index('module');
            $table->index('issue_type');
            $table->index('severity');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_issues');
    }
};
