<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('payment_method', 30)->nullable()->after('status');
            $table->string('payment_stage', 20)->nullable()->after('payment_method');
            $table->string('idempotency_key', 100)->nullable()->unique()->after('payment_stage');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE payments ADD CONSTRAINT payments_payment_method_check '
                .'CHECK (payment_method IS NULL OR payment_method IN '
                ."('evc_plus', 'edahab', 'bank_transfer', 'cash_on_delivery', 'cash_on_service'))",
            );
            DB::statement(
                'ALTER TABLE payments ADD CONSTRAINT payments_payment_stage_check '
                ."CHECK (payment_stage IS NULL OR payment_stage IN ('deposit', 'balance', 'full'))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_payment_method_check');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_payment_stage_check');
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['payment_method', 'payment_stage', 'idempotency_key']);
        });
    }
};
