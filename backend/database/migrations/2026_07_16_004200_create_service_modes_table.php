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
        Schema::create('service_modes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('mode', 30);
            $table->string('subtype', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['service_id', 'mode', 'is_active']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE service_modes ADD CONSTRAINT service_modes_mode_check '
                ."CHECK (mode IN ('one_time', 'monthly_contract'))",
            );
            DB::statement(
                'ALTER TABLE service_modes ADD CONSTRAINT service_modes_subtype_check '
                .'CHECK (subtype IS NULL OR subtype IN '
                ."('full_time', 'part_time', 'live_in', 'live_out', 'office', 'hotel', "
                ."'restaurant', 'school', 'hospital_clinic', 'other_business'))",
            );
            DB::statement(
                'CREATE UNIQUE INDEX service_modes_unique_option '
                ."ON service_modes (service_id, mode, COALESCE(subtype, ''))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_modes');
    }
};
