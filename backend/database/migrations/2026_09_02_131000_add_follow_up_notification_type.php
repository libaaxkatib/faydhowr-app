<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Widens the 3 Postgres CHECK constraints that enumerate notification
     * types, adding 'follow_up' for the due-today/overdue reminder — reuses
     * the entire existing notification pipeline (NotificationChannelManager,
     * templates, preferences, queued delivery) untouched.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $types = "'booking', 'quotation', 'order', 'payment', 'store_order', 'inventory', 'system', 'follow_up'";

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_check');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ({$types}))");

        DB::statement('ALTER TABLE notification_templates DROP CONSTRAINT notification_templates_type_check');
        DB::statement("ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_type_check CHECK (type IN ({$types}))");

        DB::statement('ALTER TABLE notification_preferences DROP CONSTRAINT notification_preferences_type_check');
        DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_type_check CHECK (notification_type IN ({$types}))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $types = "'booking', 'quotation', 'order', 'payment', 'store_order', 'inventory', 'system'";

        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_check');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ({$types}))");

        DB::statement('ALTER TABLE notification_templates DROP CONSTRAINT notification_templates_type_check');
        DB::statement("ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_type_check CHECK (type IN ({$types}))");

        DB::statement('ALTER TABLE notification_preferences DROP CONSTRAINT notification_preferences_type_check');
        DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_type_check CHECK (notification_type IN ({$types}))");
    }
};
