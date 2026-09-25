<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRM Phase 1 (docs/HRM_MARKETING_SRS.md HR §27/§39): adds categorization
 * and verification to the previously-untyped employee_documents table, and
 * fixes the confirmed conflict where replacing a document used to hard-
 * delete it. Replacing a document now inserts a new row and points the old
 * row's superseded_by_document_id at it instead of destroying it -
 * is_current makes "show me the active one" a plain indexed lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_documents', function (Blueprint $table): void {
            $table->foreignId('employee_document_category_id')->nullable()->after('admin_id')
                ->constrained('employee_document_categories')->nullOnDelete();
            $table->string('document_number', 100)->nullable()->after('file_path');
            $table->string('verification_status', 20)->default('pending')->after('document_number');
            $table->timestampTz('verified_at')->nullable()->after('verification_status');
            $table->foreignId('verified_by')->nullable()->after('verified_at')
                ->constrained('admins')->nullOnDelete();
            $table->date('expiry_date')->nullable()->after('verified_by');
            $table->foreignId('superseded_by_document_id')->nullable()->after('expiry_date')
                ->constrained('employee_documents')->nullOnDelete();
            $table->boolean('is_current')->default(true)->after('superseded_by_document_id');

            $table->index(['employee_id', 'is_current']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE employee_documents ADD CONSTRAINT employee_documents_verification_status_check '
                ."CHECK (verification_status IN ('pending', 'verified', 'rejected'))",
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE employee_documents DROP CONSTRAINT IF EXISTS employee_documents_verification_status_check');
        }

        Schema::table('employee_documents', function (Blueprint $table): void {
            $table->dropIndex(['employee_id', 'is_current']);
            $table->dropConstrainedForeignId('superseded_by_document_id');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropConstrainedForeignId('employee_document_category_id');
            $table->dropColumn(['document_number', 'verification_status', 'verified_at', 'expiry_date', 'is_current']);
        });
    }
};
