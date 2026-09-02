<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * follow_up_date is an actual date column — docs/HRM_MARKETING_SRS.md
     * §14's explicit rule: never store "1 month later" as text.
     */
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_record_id')->constrained('marketing_records')->cascadeOnDelete();
            $table->date('follow_up_date');
            $table->string('status', 20)->default('scheduled');
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['marketing_record_id', 'follow_up_date']);
            $table->index(['status', 'follow_up_date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE follow_ups ADD CONSTRAINT follow_ups_status_check '
                ."CHECK (status IN ('scheduled', 'completed', 'rescheduled'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
