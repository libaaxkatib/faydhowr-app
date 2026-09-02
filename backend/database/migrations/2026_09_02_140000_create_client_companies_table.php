<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * External businesses that request Fayadhowr staff (e.g. a cleaning contract
 * client). Deliberately separate from Marketing's xarun_details/marketing_records,
 * which model a sales-lead/prospect facility, not an operational staffing
 * relationship — see the HRM Work Assignments implementation report for the
 * reconciliation. The Fayadhowr office itself is not a row here; it lives in
 * work_locations as a location_type='office' row with no client_company_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('contact_person', 150)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('location', 150)->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE client_companies ADD CONSTRAINT client_companies_status_check '
                ."CHECK (status IN ('active', 'inactive'))",
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_companies');
    }
};
