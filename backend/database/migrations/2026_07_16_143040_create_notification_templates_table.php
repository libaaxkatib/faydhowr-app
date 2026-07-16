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
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('template_key', 100);
            $table->string('name', 150);
            $table->string('type', 30);
            $table->string('channel', 30);
            $table->string('language', 10)->default('en');
            $table->string('subject', 255)->nullable();
            $table->string('title', 255);
            $table->text('message');
            $table->string('status', 30)->default('active');
            $table->json('variables')->nullable();
            $table->timestamps();

            $table->unique('template_key');
            $table->index(['status']);
            $table->index(['type']);
            $table->index(['channel']);
            $table->index(['language']);
            $table->index(['status', 'type']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_type_check '
                ."CHECK (type IN ('booking', 'quotation', 'order', 'payment', 'store_order', 'inventory', 'system'))",
            );
            DB::statement(
                'ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_channel_check '
                ."CHECK (channel IN ('in_app', 'email', 'sms'))",
            );
            DB::statement(
                'ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_status_check '
                ."CHECK (status IN ('active', 'inactive'))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
