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
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->string('recipient_type', 100);
            $table->unsignedBigInteger('recipient_id');
            $table->string('notification_type', 30);
            $table->boolean('in_app')->default(true);
            $table->boolean('email')->default(true);
            $table->boolean('sms')->default(false);
            $table->timestamps();

            $table->unique(
                ['recipient_type', 'recipient_id', 'notification_type'],
                'notification_preferences_recipient_type_unique',
            );
            $table->index(['recipient_type', 'recipient_id']);
            $table->index(['notification_type']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_type_check '
                ."CHECK (notification_type IN ('booking', 'quotation', 'order', 'payment', 'store_order', 'inventory', 'system'))",
            );
            DB::statement(
                'ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_recipient_type_check '
                ."CHECK (recipient_type IN ('App\\Models\\Admin', 'App\\Models\\CustomerProfile'))",
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
