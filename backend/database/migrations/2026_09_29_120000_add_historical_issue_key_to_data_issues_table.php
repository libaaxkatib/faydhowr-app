<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stable idempotency key for historical/bulk-imported Data Issues (e.g.
 * "hr_audit_issue_08") — importing the same historical dataset twice must
 * never create duplicate issue records. Null for issues created normally
 * through the UI, which have no such external identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_issues', function (Blueprint $table): void {
            $table->string('historical_issue_key', 60)->nullable()->unique()->after('issue_number');
        });
    }

    public function down(): void
    {
        Schema::table('data_issues', function (Blueprint $table): void {
            $table->dropUnique(['historical_issue_key']);
            $table->dropColumn('historical_issue_key');
        });
    }
};
