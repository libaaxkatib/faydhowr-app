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
        Schema::table('notifications', function (Blueprint $table): void {
            $table->timestampTz('processing_started_at')->nullable()->after('data');
            $table->timestampTz('delivered_at')->nullable()->after('sent_at');
            $table->timestampTz('failed_at')->nullable()->after('read_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_status_check');
            DB::statement(
                'ALTER TABLE notifications ADD CONSTRAINT notifications_status_check '
                ."CHECK (status IN ('pending', 'processing', 'sent', 'delivered', 'read', 'failed'))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_status_check');
            DB::statement(
                'ALTER TABLE notifications ADD CONSTRAINT notifications_status_check '
                ."CHECK (status IN ('pending', 'sent', 'failed', 'read'))",
            );
        }

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn(['processing_started_at', 'delivered_at', 'failed_at']);
        });
    }
};
