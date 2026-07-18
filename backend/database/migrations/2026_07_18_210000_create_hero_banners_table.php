<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Home hero carousel banners (Database Design §3.13.1): admin-managed
     * display copy with an optional call-to-action and schedule window.
     */
    public function up(): void
    {
        Schema::create('hero_banners', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->string('subtitle', 500)->nullable();
            $table->string('image_url', 2048);
            $table->string('action_type', 20)->default('none');
            $table->string('action_reference', 2048)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
            $table->index(['starts_at', 'ends_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE hero_banners ADD CONSTRAINT hero_banners_action_type_check '
                ."CHECK (action_type IN ('service', 'product', 'category', 'url', 'none'))",
            );
            DB::statement(
                'ALTER TABLE hero_banners ADD CONSTRAINT hero_banners_action_reference_check '
                ."CHECK ((action_type = 'none' AND action_reference IS NULL) "
                ."OR (action_type <> 'none' AND action_reference IS NOT NULL))",
            );
            DB::statement(
                'ALTER TABLE hero_banners ADD CONSTRAINT hero_banners_schedule_check '
                .'CHECK (starts_at IS NULL OR ends_at IS NULL OR ends_at > starts_at)',
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hero_banners');
    }
};
