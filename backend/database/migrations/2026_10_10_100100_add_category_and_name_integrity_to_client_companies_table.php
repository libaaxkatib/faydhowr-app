<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approved decisions: companies are classified as `company` (a business or
 * organization) or `guri` (a household / private residence), editable later
 * without recreating the record; and company names must be unique ignoring
 * upper/lower case, so "TransitHotel" and "TRANSITHOTEL" can never coexist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_companies', function (Blueprint $table): void {
            $table->string('category', 20)->default('company')->after('name');
            $table->index('category');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE client_companies ADD CONSTRAINT client_companies_category_check '
                ."CHECK (category IN ('company', 'guri'))",
            );
        }

        DB::statement('CREATE UNIQUE INDEX client_companies_name_lower_unique ON client_companies (lower(name))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS client_companies_name_lower_unique');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE client_companies DROP CONSTRAINT IF EXISTS client_companies_category_check');
        }

        Schema::table('client_companies', function (Blueprint $table): void {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
