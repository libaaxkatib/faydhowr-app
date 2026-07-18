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
        Schema::table('services', function (Blueprint $table): void {
            $table->string('payment_type', 30)->default('full_before_service')->after('sort_order');
            $table->unsignedTinyInteger('deposit_percentage')->nullable()->after('payment_type');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE services ADD CONSTRAINT services_payment_type_check '
                ."CHECK (payment_type IN ('full_before_service', 'deposit', 'pay_after_service'))",
            );
            DB::statement(
                'ALTER TABLE services ADD CONSTRAINT services_deposit_percentage_check '
                ."CHECK ((payment_type = 'deposit' AND deposit_percentage BETWEEN 1 AND 99) "
                ."OR (payment_type <> 'deposit' AND deposit_percentage IS NULL))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_payment_type_check');
            DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_deposit_percentage_check');
        }

        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn(['payment_type', 'deposit_percentage']);
        });
    }
};
