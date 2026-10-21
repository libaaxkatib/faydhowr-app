<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A category that is still referenced by people can be ARCHIVED (hidden from the pick-lists used for new registrations) instead of deleted.
 * Archiving changes no employee, request or report; restoring simply makes the category selectable again. Nullable and additive: every
 * existing category stays active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_categories', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('employee_categories', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
