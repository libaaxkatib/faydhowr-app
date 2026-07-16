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
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('recipient_type', 100);
            $table->unsignedBigInteger('recipient_id');
            $table->string('type', 30);
            $table->string('channel', 30);
            $table->string('status', 30)->default('pending');
            $table->string('title', 255);
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_type', 'recipient_id']);
            $table->index(['recipient_type', 'recipient_id', 'status']);
            $table->index(['type']);
            $table->index(['channel']);
            $table->index(['status']);
            $table->index(['created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE notifications ADD CONSTRAINT notifications_type_check '
                ."CHECK (type IN ('booking', 'quotation', 'order', 'payment', 'store_order', 'inventory', 'system'))",
            );
            DB::statement(
                'ALTER TABLE notifications ADD CONSTRAINT notifications_channel_check '
                ."CHECK (channel IN ('in_app', 'email', 'sms'))",
            );
            DB::statement(
                'ALTER TABLE notifications ADD CONSTRAINT notifications_status_check '
                ."CHECK (status IN ('pending', 'sent', 'failed', 'read'))",
            );
            DB::statement(
                'ALTER TABLE notifications ADD CONSTRAINT notifications_recipient_type_check '
                ."CHECK (recipient_type IN ('App\\Models\\Admin', 'App\\Models\\CustomerProfile'))",
            );
            DB::statement(
                'ALTER TABLE notifications ADD CONSTRAINT notifications_read_consistency_check '
                ."CHECK ((status = 'read' AND read_at IS NOT NULL) OR (status <> 'read'))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
