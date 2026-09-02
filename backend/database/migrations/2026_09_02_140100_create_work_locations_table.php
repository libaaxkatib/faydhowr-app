<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unifies a client company's sites (ABC Main Center, Branch 2, ...) and
 * Fayadhowr's own office into one table via location_type, so capacity/
 * status/contact fields apply uniformly "where applicable" to either kind.
 * client_company_id is required for location_type=client and must be null
 * for location_type=office (enforced at the FormRequest layer). Seeds the
 * one canonical "Fayadhowr Office" row (capacity 15, editable) so office
 * capacity enforcement works from day one without a manual setup step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_company_id')->nullable()->constrained('client_companies')->restrictOnDelete();
            $table->string('location_type', 20);
            $table->string('name', 150);
            $table->string('location', 150)->nullable();
            $table->string('contact_person', 150)->nullable();
            $table->string('phone', 40)->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['client_company_id', 'name']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE work_locations ADD CONSTRAINT work_locations_location_type_check '
                ."CHECK (location_type IN ('client', 'office'))",
            );
            DB::statement(
                'ALTER TABLE work_locations ADD CONSTRAINT work_locations_status_check '
                ."CHECK (status IN ('active', 'inactive'))",
            );
        }

        DB::table('work_locations')->insert([
            'client_company_id' => null,
            'location_type' => 'office',
            'name' => 'Fayadhowr Office',
            'location' => null,
            'contact_person' => null,
            'phone' => null,
            'capacity' => 15,
            'status' => 'active',
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('work_locations');
    }
};
