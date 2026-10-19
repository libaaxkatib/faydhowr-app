<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Commission is recorded manually: no approved formula exists, so the
     * system never calculates an amount. This only widens the allowed status
     * values on PostgreSQL to include 'recorded'; no row is changed.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE commission_records DROP CONSTRAINT IF EXISTS commission_records_status_check');
        DB::statement(
            'ALTER TABLE commission_records ADD CONSTRAINT commission_records_status_check '
            ."CHECK (status IN ('pending_calculation', 'recorded', 'calculated', 'approved', 'paid'))",
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("UPDATE commission_records SET status = 'pending_calculation' WHERE status = 'recorded'");
        DB::statement('ALTER TABLE commission_records DROP CONSTRAINT IF EXISTS commission_records_status_check');
        DB::statement(
            'ALTER TABLE commission_records ADD CONSTRAINT commission_records_status_check '
            ."CHECK (status IN ('pending_calculation', 'calculated', 'approved', 'paid'))",
        );
    }
};
