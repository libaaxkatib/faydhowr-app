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
        Schema::create('archived_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('original_notification_id');
            $table->string('recipient_type', 100);
            $table->unsignedBigInteger('recipient_id');
            $table->string('type', 30);
            $table->string('channel', 30);
            $table->string('status', 30);
            $table->string('title', 255);
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('archived_at');
            $table->timestampTz('created_at');

            $table->unique('original_notification_id');
            $table->index(['recipient_type', 'recipient_id']);
            $table->index(['type']);
            $table->index(['status']);
            $table->index(['archived_at']);
            $table->index(['created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE archived_notifications ADD CONSTRAINT archived_notifications_type_check '
                ."CHECK (type IN ('booking', 'quotation', 'order', 'payment', 'store_order', 'inventory', 'system'))",
            );
            DB::statement(
                'ALTER TABLE archived_notifications ADD CONSTRAINT archived_notifications_channel_check '
                ."CHECK (channel IN ('in_app', 'email', 'sms'))",
            );
            DB::statement(
                'ALTER TABLE archived_notifications ADD CONSTRAINT archived_notifications_status_check '
                ."CHECK (status IN ('read', 'failed'))",
            );
            DB::statement(
                'ALTER TABLE archived_notifications ADD CONSTRAINT archived_notifications_recipient_type_check '
                ."CHECK (recipient_type IN ('App\\Models\\Admin', 'App\\Models\\CustomerProfile'))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('archived_notifications');
    }
};
