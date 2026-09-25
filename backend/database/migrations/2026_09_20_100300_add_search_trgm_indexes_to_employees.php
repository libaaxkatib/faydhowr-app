<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HRM Phase 7 (Security/Hardening audit, P7-17): additive index only, no
 * behavior change. ListEmployeesAction's search filter runs a leading-
 * wildcard ILIKE against full_name/phone/employee_number with no supporting
 * index - mirrors the existing pg_trgm pattern already used for
 * services/products (see 2026_07_18_210400_add_search_trgm_indexes.php).
 * The SQLite automated-test environment uses the LIKE fallback and needs no
 * indexes here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('CREATE INDEX IF NOT EXISTS employees_full_name_trgm_index ON employees USING GIN (full_name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS employees_phone_trgm_index ON employees USING GIN (phone gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS employees_employee_number_trgm_index ON employees USING GIN (employee_number gin_trgm_ops)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS employees_full_name_trgm_index');
        DB::statement('DROP INDEX IF EXISTS employees_phone_trgm_index');
        DB::statement('DROP INDEX IF EXISTS employees_employee_number_trgm_index');
    }
};
