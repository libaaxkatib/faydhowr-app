<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_status_check');
        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_status_check '
            ."CHECK (status IN ('submitted', 'pending_review', 'quotation_ready', "
            ."'under_discussion', 'accepted', 'scheduled', 'in_progress', "
            ."'completed', 'closed', 'cancelled'))",
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_status_check');
        DB::statement(
            'ALTER TABLE bookings ADD CONSTRAINT bookings_status_check '
            ."CHECK (status IN ('submitted', 'pending_review', 'quotation_ready', "
            ."'under_discussion', 'accepted', 'scheduled', 'in_progress', "
            ."'completed', 'cancelled'))",
        );
    }
};
