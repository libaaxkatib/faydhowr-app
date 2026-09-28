<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR Excel migration (write-mode) support fields, none of which existed as a
 * real column before — see ExcelMigrationService/ResolvedPerson for the
 * confirmed business rules each one backs:
 *   - guarantor_needed: independent flag (REGISTRATION "other color"/no fill,
 *     or NEED DAMIIN sheet membership), deliberately not a pipeline_stage.
 *   - category_specialization: Home Cleaning Work Type or Cooking
 *     Specialization, null for every other category.
 *   - profile_complete: false for every Excel-migrated person by definition
 *     (see ResolvedPerson::$profileIncompleteReasons) until an admin
 *     completes the profile in the Web Panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->boolean('guarantor_needed')->default(false)->after('guarantor_confirmed_at');
            $table->string('category_specialization', 60)->nullable()->after('employee_category_id');
            $table->boolean('profile_complete')->default(false)->after('notes');

            $table->index('guarantor_needed');
            $table->index('profile_complete');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex(['guarantor_needed']);
            $table->dropIndex(['profile_complete']);
            $table->dropColumn(['guarantor_needed', 'category_specialization', 'profile_complete']);
        });
    }
};
