<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('service_coverage_cities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('city', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['service_id', 'city']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_coverage_cities');
    }
};
