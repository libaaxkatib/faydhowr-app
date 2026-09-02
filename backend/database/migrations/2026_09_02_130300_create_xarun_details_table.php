<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * XARUN-only fields per docs/HRM_MARKETING_SRS.md §7-8. `needs` is a
     * JSON array — §8 explicitly requires multiple needs to be selectable.
     */
    public function up(): void
    {
        Schema::create('xarun_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_record_id')->unique()->constrained('marketing_records')->cascadeOnDelete();
            $table->string('facility_name', 200);
            $table->string('manager_name', 150)->nullable();
            $table->string('manager_title', 100)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('location', 150)->nullable();
            $table->json('needs')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xarun_details');
    }
};
