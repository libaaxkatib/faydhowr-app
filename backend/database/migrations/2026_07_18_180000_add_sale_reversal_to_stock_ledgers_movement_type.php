<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Extends the stock ledger movement catalog with `sale_reversal`
     * (Sprint 27): automatic inventory restock after a COD payment
     * rejection cancels the store order.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_ledgers DROP CONSTRAINT IF EXISTS stock_ledgers_movement_type_check');
            DB::statement(
                'ALTER TABLE stock_ledgers ADD CONSTRAINT stock_ledgers_movement_type_check '
                ."CHECK (movement_type IN ('purchase_receipt', 'customer_sale', 'sale_reversal', 'adjustment', 'correction', 'damage', 'loss'))",
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_ledgers DROP CONSTRAINT IF EXISTS stock_ledgers_movement_type_check');
            DB::statement(
                'ALTER TABLE stock_ledgers ADD CONSTRAINT stock_ledgers_movement_type_check '
                ."CHECK (movement_type IN ('purchase_receipt', 'customer_sale', 'adjustment', 'correction', 'damage', 'loss'))",
            );
        }
    }
};
