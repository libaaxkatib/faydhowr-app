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
            $table->string('event_id', 100)->nullable()->after('data');
        });

        DB::table('notifications')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $data = is_string($row->data) ? json_decode($row->data, true) : $row->data;
                    $eventId = is_array($data) ? ($data['event_id'] ?? null) : null;

                    if (! is_string($eventId) || $eventId === '') {
                        $eventId = 'legacy-'.$row->id;
                    }

                    DB::table('notifications')
                        ->where('id', $row->id)
                        ->update(['event_id' => $eventId]);
                }
            });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->unique(
                ['recipient_type', 'recipient_id', 'channel', 'event_id'],
                'notifications_recipient_channel_event_uidx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropUnique('notifications_recipient_channel_event_uidx');
            $table->dropColumn('event_id');
        });
    }
};
