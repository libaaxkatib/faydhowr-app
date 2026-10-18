<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee documents can live on more than one storage disk once private object storage is introduced, and every file
 * needs a recorded checksum so it can be verified when it is served, mirrored and restored.
 *
 * - `disk`   : which storage disk holds the file. Existing rows get 'local' (where every document is today), so nothing
 *              already uploaded changes meaning, and a later change of the default disk cannot strand an older file.
 *              Mirrors the `disk` column the company-agreement table already has.
 * - `sha256` : SHA-256 of the file as stored. Nullable: documents uploaded before this column existed have no recorded
 *              checksum, and none is invented for them - the inventory and mirror commands report them as "unrecorded".
 *
 * Additive and safe to run more than once. No data is read, moved or changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('employee_documents', 'disk')) {
                $table->string('disk', 50)->default('local')->after('file_path');
            }

            if (! Schema::hasColumn('employee_documents', 'sha256')) {
                $table->char('sha256', 64)->nullable()->after('disk');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_documents', function (Blueprint $table): void {
            if (Schema::hasColumn('employee_documents', 'sha256')) {
                $table->dropColumn('sha256');
            }

            if (Schema::hasColumn('employee_documents', 'disk')) {
                $table->dropColumn('disk');
            }
        });
    }
};
