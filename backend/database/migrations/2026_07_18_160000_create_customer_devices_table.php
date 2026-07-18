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
        Schema::create('customer_devices', function (Blueprint $table): void {
            $table->id();
            $table->string('device_id', 100);
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('platform', 20);
            $table->string('push_token', 255)->nullable();
            $table->string('app_version', 30)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'device_id']);
            $table->index(['user_id', 'is_active']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE customer_devices ADD CONSTRAINT customer_devices_platform_check '
                ."CHECK (platform IN ('ios', 'android'))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_devices');
    }
};
