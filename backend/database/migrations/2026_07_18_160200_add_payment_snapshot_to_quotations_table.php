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
        Schema::table('quotations', function (Blueprint $table): void {
            $table->string('payment_type', 30)->nullable()->after('total_amount');
            $table->unsignedTinyInteger('deposit_percentage')->nullable()->after('payment_type');
            $table->decimal('deposit_amount', 12, 2)->nullable()->after('deposit_percentage');
            $table->decimal('remaining_amount', 12, 2)->nullable()->after('deposit_amount');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE quotations ADD CONSTRAINT quotations_payment_type_check '
                ."CHECK (payment_type IS NULL OR payment_type IN ('full_before_service', 'deposit', 'pay_after_service'))",
            );
            DB::statement(
                'ALTER TABLE quotations ADD CONSTRAINT quotations_deposit_snapshot_check '
                ."CHECK ((payment_type = 'deposit' AND deposit_percentage BETWEEN 1 AND 99 "
                .'AND deposit_amount IS NOT NULL AND remaining_amount IS NOT NULL) '
                ."OR (payment_type IS DISTINCT FROM 'deposit' AND deposit_percentage IS NULL AND deposit_amount IS NULL))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE quotations DROP CONSTRAINT IF EXISTS quotations_payment_type_check');
            DB::statement('ALTER TABLE quotations DROP CONSTRAINT IF EXISTS quotations_deposit_snapshot_check');
        }

        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropColumn(['payment_type', 'deposit_percentage', 'deposit_amount', 'remaining_amount']);
        });
    }
};
