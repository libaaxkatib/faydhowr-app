<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly Deep Cleaning tracking inside the Facility Inspection. Additive: one new table, nothing existing is touched, nothing is backfilled.
 *
 * Why a table of its own and not a thirteenth checklist item: the checklist is exactly twelve items (enforced by a CHECK constraint and by the
 * request rules) and an inspection edit REPLACES its items, which would erase a recorded completion. A completion also has its own date (the day
 * the cleaning happened, not the day it was entered), its own notes and its own audit trail.
 *
 *  - facility_deep_cleanings   one row per recorded completion. It belongs to the inspection that recorded it (UNIQUE: an inspection records at
 *                              most one, so a repeated submission cannot count twice) and repeats client_company_id so monthly reports need no join.
 *                              A completion is WITHDRAWN (voided_at, voided_by, void_reason), never deleted; a withdrawn row is kept as history
 *                              and no status or report counts it. Voiding the inspection withdraws its completion too.
 *
 * A routine inspection creates no row: "no row" means "no Deep Cleaning recorded", never "completed". Safe to roll back while the table holds no
 * data worth keeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_deep_cleanings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('facility_inspection_id')->constrained('facility_inspections')->restrictOnDelete();
            $table->foreignId('client_company_id')->constrained('client_companies')->restrictOnDelete();
            $table->date('completed_on');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('admins')->nullOnDelete();
            // Set only when a recorded completion is corrected later; the previous values stay in the audit log with the reason.
            $table->foreignId('changed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('change_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->unique('facility_inspection_id');
            $table->index(['client_company_id', 'completed_on']);
            $table->index('completed_on');
        });

        // The same cleaning of the same facility on the same day cannot be recorded twice (a second form submission or a second inspection).
        // A withdrawn row does not block a correct re-entry. Partial unique indexes exist on both PostgreSQL (production) and SQLite (tests).
        DB::statement('CREATE UNIQUE INDEX facility_deep_cleanings_company_day_unique ON facility_deep_cleanings (client_company_id, completed_on) WHERE voided_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_deep_cleanings');
    }
};
