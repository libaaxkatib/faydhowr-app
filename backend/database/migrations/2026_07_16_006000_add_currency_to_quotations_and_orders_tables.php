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
            $table->char('currency', 3)->default('USD')->after('status');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->char('currency', 3)->default('USD')->after('status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE quotations ADD CONSTRAINT quotations_currency_format_check '
                ."CHECK (currency ~ '^[A-Z]{3}$')",
            );
            DB::statement(
                'ALTER TABLE orders ADD CONSTRAINT orders_currency_format_check '
                ."CHECK (currency ~ '^[A-Z]{3}$')",
            );
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION enforce_immutable_currency() RETURNS trigger AS $$
                BEGIN
                    IF NEW.currency IS DISTINCT FROM OLD.currency THEN
                        RAISE EXCEPTION 'Currency cannot be changed after record creation.';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::statement(
                'CREATE TRIGGER quotations_currency_immutable '
                .'BEFORE UPDATE OF currency ON quotations '
                .'FOR EACH ROW EXECUTE FUNCTION enforce_immutable_currency()',
            );
            DB::statement(
                'CREATE TRIGGER orders_currency_immutable '
                .'BEFORE UPDATE OF currency ON orders '
                .'FOR EACH ROW EXECUTE FUNCTION enforce_immutable_currency()',
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS orders_currency_immutable ON orders');
            DB::statement('DROP TRIGGER IF EXISTS quotations_currency_immutable ON quotations');
            DB::statement('DROP FUNCTION IF EXISTS enforce_immutable_currency()');
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });
    }
};
