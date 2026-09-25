<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 2: the confirmed-match record ("HR confirmation") - permanent
 * history of which Waiting employee was matched to which workforce request.
 * Confirming a match never changes employee.status/pipeline_stage; moving an
 * employee to Active and creating the real work assignment is Phase 3
 * business, done separately via the pre-existing Advance/Assign flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_request_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workforce_request_id')->constrained('workforce_requests')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['workforce_request_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_request_matches');
    }
};
