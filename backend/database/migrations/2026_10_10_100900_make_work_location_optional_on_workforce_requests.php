<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Same approved rule as for assignments: a company can ask for staff before the
 * exact workplace is known. The company is recorded on the request
 * (client_company_id) and the work location is optional. Existing requests are
 * backfilled from their location's company. A request must name a company OR a
 * location (a request for the Fayadhowr Office has a location and no company).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workforce_requests', function (Blueprint $table): void {
            $table->foreignId('client_company_id')->nullable()->after('work_location_id')
                ->constrained('client_companies')->restrictOnDelete();
            $table->index('client_company_id');
        });

        Schema::table('workforce_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('work_location_id')->nullable()->change();
        });

        DB::statement(
            'UPDATE workforce_requests SET client_company_id = ('
            .'SELECT work_locations.client_company_id FROM work_locations '
            .'WHERE work_locations.id = workforce_requests.work_location_id)',
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE workforce_requests ADD CONSTRAINT workforce_requests_company_or_location_check '
                .'CHECK (work_location_id IS NOT NULL OR client_company_id IS NOT NULL)',
            );
        }
    }

    public function down(): void
    {
        if (DB::table('workforce_requests')->whereNull('work_location_id')->exists()) {
            throw new RuntimeException('Cannot roll back: some workforce requests have no work location. Assign locations first.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE workforce_requests DROP CONSTRAINT IF EXISTS workforce_requests_company_or_location_check');
        }

        Schema::table('workforce_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('work_location_id')->nullable(false)->change();
        });

        Schema::table('workforce_requests', function (Blueprint $table): void {
            $table->dropIndex(['client_company_id']);
            $table->dropConstrainedForeignId('client_company_id');
        });
    }
};
