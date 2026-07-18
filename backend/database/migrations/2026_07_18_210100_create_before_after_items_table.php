<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Global Home Before & After gallery items (Database Design §3.13.2)
     * with optional service attribution.
     */
    public function up(): void
    {
        Schema::create('before_after_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')
                ->nullable()
                ->constrained('services')
                ->nullOnDelete();
            $table->string('title', 200);
            $table->string('before_image_url', 2048);
            $table->string('after_image_url', 2048);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('before_after_items');
    }
};
