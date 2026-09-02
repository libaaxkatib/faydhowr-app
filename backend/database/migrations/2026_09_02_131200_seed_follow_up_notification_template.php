<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the template the follow-ups:notify-due command renders. Same
     * pattern as 2026_07_18_180200_seed_operational_notification_templates.
     */
    public function up(): void
    {
        $exists = DB::table('notification_templates')->where('template_key', 'follow_up_due_today')->exists();

        if (! $exists) {
            DB::table('notification_templates')->insert([
                'template_key' => 'follow_up_due_today',
                'name' => 'Follow-up Due Today',
                'type' => 'follow_up',
                'channel' => 'in_app',
                'language' => 'en',
                'status' => 'active',
                'title' => 'Follow-up due today',
                'message' => 'Follow-up for {{record_number}} ({{record_type}}) is due today.',
                'variables' => json_encode(['record_number', 'record_type']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('template_key', 'follow_up_due_today')->delete();
    }
};
