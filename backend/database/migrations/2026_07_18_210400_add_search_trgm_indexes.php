<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * PostgreSQL pg_trgm GIN indexes on searched catalog columns (Database
     * Design §5.2, API Design §15). The SQLite automated-test environment
     * uses the LIKE fallback and needs no indexes here.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('CREATE INDEX IF NOT EXISTS services_name_trgm_index ON services USING GIN (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS services_short_description_trgm_index ON services USING GIN (short_description gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS products_name_trgm_index ON products USING GIN (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS products_description_trgm_index ON products USING GIN (description gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS services_name_trgm_index');
        DB::statement('DROP INDEX IF EXISTS services_short_description_trgm_index');
        DB::statement('DROP INDEX IF EXISTS products_name_trgm_index');
        DB::statement('DROP INDEX IF EXISTS products_description_trgm_index');
    }
};
