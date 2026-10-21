<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff and customer complaints can now be recorded inside the Facility Inspection itself.
 *
 *  - facility_inspections.staff_complaints_reported / customer_complaints_reported: the inspector's explicit answer. true = at least one
 *    complaint of that kind was recorded, false = "no complaint", NULL = not recorded (every inspection saved before this change).
 *  - facility_complaints.corrective_action / follow_up_on: what will be done about the complaint and when it is checked again.
 *  - facility_complaints.reporter_phone: contact of a customer who complained (customers are recorded as details, never as employees).
 *
 * Additive only: no existing value changes. Safe to roll back while the new columns hold no data worth keeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facility_inspections', function (Blueprint $table): void {
            $table->boolean('staff_complaints_reported')->nullable()->after('next_follow_up_on');
            $table->boolean('customer_complaints_reported')->nullable()->after('staff_complaints_reported');
        });

        Schema::table('facility_complaints', function (Blueprint $table): void {
            $table->text('corrective_action')->nullable()->after('response');
            $table->date('follow_up_on')->nullable()->after('corrective_action');
            $table->string('reporter_phone', 40)->nullable()->after('reporter_type');
        });
    }

    public function down(): void
    {
        Schema::table('facility_complaints', function (Blueprint $table): void {
            $table->dropColumn(['corrective_action', 'follow_up_on', 'reporter_phone']);
        });

        Schema::table('facility_inspections', function (Blueprint $table): void {
            $table->dropColumn(['staff_complaints_reported', 'customer_complaints_reported']);
        });
    }
};
