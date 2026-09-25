<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 1 (docs/HRM_MARKETING_SRS.md HR §29): a profile picture is
 * stored as a categorized document (see employee_document_categories'
 * "Profile Picture" row) and referenced directly from the employee row for
 * fast access without a join. Nullable - falls back to initials in the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('profile_picture_document_id')->nullable()->after('reference_name')
                ->constrained('employee_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('profile_picture_document_id');
        });
    }
};
