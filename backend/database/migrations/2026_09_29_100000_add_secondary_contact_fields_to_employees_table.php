<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR Issue #1 fix: the employee's own secondary/emergency contact (Excel
 * "Other Contact"/"Other Contact Name" — see ResolvedPerson::$secondaryContactPhone)
 * never had a dedicated column and was being appended as free text into
 * `notes` by ExcelMigrationCommitter::buildEmployeeNotes(). This is explicitly
 * NOT the guarantor/Damiin (see `reference_name`, labeled "Reference / guarantor"
 * in the UI) — a separate, already-established concept that must not be
 * conflated with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('secondary_contact_name', 150)->nullable()->after('reference_name');
            $table->string('secondary_contact_phone', 40)->nullable()->after('secondary_contact_name');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['secondary_contact_name', 'secondary_contact_phone']);
        });
    }
};
