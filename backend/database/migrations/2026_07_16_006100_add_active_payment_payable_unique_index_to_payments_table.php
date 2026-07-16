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
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX payments_active_payable_unique '
                .'ON payments (payable_type, payable_id) '
                ."WHERE status IN ('pending', 'initialized', 'processing') AND deleted_at IS NULL",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS payments_active_payable_unique');
        }
    }
};
