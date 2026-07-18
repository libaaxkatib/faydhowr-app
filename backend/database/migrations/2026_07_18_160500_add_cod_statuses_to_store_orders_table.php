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

        DB::statement('ALTER TABLE store_orders DROP CONSTRAINT IF EXISTS store_orders_status_check');
        DB::statement(
            'ALTER TABLE store_orders ADD CONSTRAINT store_orders_status_check '
            ."CHECK (status IN ('pending_payment', 'confirmed', 'processing', 'preparing', "
            ."'out_for_delivery', 'delivered', 'payment_pending', 'completed', 'cancelled'))",
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

        DB::statement('ALTER TABLE store_orders DROP CONSTRAINT IF EXISTS store_orders_status_check');
        DB::statement(
            'ALTER TABLE store_orders ADD CONSTRAINT store_orders_status_check '
            ."CHECK (status IN ('pending_payment', 'confirmed', 'processing', 'completed', 'cancelled'))",
        );
    }
};
