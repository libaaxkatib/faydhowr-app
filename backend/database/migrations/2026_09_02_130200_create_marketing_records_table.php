<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shared parent for XARUN and PROJECT records — the exact shape
     * docs/HRM_MARKETING_SRS.md §34 itself proposes (marketing_records +
     * xarun_details + project_details), a `type` discriminator with
     * type-specific detail in child tables. XARUN and PROJECT still use
     * completely separate forms/endpoints per §6/§36 — only the shared
     * lifecycle fields (status, assignment, follow-up, description,
     * feedback) live here.
     */
    public function up(): void
    {
        Schema::create('marketing_records', function (Blueprint $table): void {
            $table->id();
            $table->string('record_number', 20)->unique();
            $table->string('type', 20);
            $table->foreignId('assigned_team_id')->nullable()->constrained('marketing_teams')->nullOnDelete();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->text('description')->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('brought_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
            $table->index('status');
            $table->index('assigned_team_id');
            $table->index('assigned_admin_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE marketing_records ADD CONSTRAINT marketing_records_type_check '
                ."CHECK (type IN ('xarun', 'project'))",
            );
            DB::statement(
                'ALTER TABLE marketing_records ADD CONSTRAINT marketing_records_status_check '
                ."CHECK (status IN ('pending', 'quotation', 'done', 'cancelled'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_records');
    }
};
