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
        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')
                ->constrained('service_categories')
                ->restrictOnDelete();
            $table->string('name', 200);
            $table->string('slug', 220)->unique();
            $table->string('short_description', 500)->nullable();
            $table->text('description')->nullable();
            $table->text('inclusions')->nullable();
            $table->text('exclusions')->nullable();
            $table->decimal('starting_from_price', 12, 2)->nullable();
            $table->char('currency', 3);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('min_lead_hours')->nullable();
            $table->unsignedInteger('max_concurrent_bookings')->nullable();
            $table->boolean('requires_address')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'category_id', 'sort_order']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE services ADD CONSTRAINT services_starting_from_price_check '
                .'CHECK (starting_from_price IS NULL OR starting_from_price >= 0)',
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
