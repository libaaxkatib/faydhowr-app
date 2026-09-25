<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 2 (Waiting & Company Matching): a fast, denormalized timestamp
 * for "how long has this person been waiting" instead of deriving it from
 * employee_status_histories on every list render. Set when Practical is
 * Approved (RecordPracticalDecisionAction), cleared whenever status leaves
 * Waiting for any reason (UpdateEmployeeStatusAction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->timestampTz('waiting_since')->nullable()->after('guarantor_confirmed_at');
            $table->index('waiting_since');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex(['waiting_since']);
            $table->dropColumn('waiting_since');
        });
    }
};
