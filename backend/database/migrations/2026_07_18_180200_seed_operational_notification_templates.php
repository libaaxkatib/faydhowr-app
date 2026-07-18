<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the five mandatory V1 transactional notification templates
     * (Sprint 27, API Design §13.7): Payment Confirmed, Payment Rejected,
     * Booking Scheduled, Booking Completed, Booking Cancelled.
     * Existing keys are left untouched so admin edits are preserved.
     */
    public function up(): void
    {
        $now = now();

        foreach ($this->templates() as $template) {
            $exists = DB::table('notification_templates')
                ->where('template_key', $template['template_key'])
                ->exists();

            if (! $exists) {
                DB::table('notification_templates')->insert($template + [
                    'channel' => 'in_app',
                    'language' => 'en',
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')
            ->whereIn('template_key', array_column($this->templates(), 'template_key'))
            ->delete();
    }

    /**
     * @return list<array{template_key: string, name: string, type: string, title: string, message: string, variables: string}>
     */
    private function templates(): array
    {
        return [
            [
                'template_key' => 'payment_confirmed',
                'name' => 'Payment Confirmed',
                'type' => 'payment',
                'title' => 'Payment Confirmed',
                'message' => 'Your payment {{payment_number}} of {{amount}} {{currency}} has been confirmed.',
                'variables' => json_encode(['payment_number', 'amount', 'currency']),
            ],
            [
                'template_key' => 'payment_rejected',
                'name' => 'Payment Rejected',
                'type' => 'payment',
                'title' => 'Payment Rejected',
                'message' => 'Your payment {{payment_number}} of {{amount}} {{currency}} was rejected. Reason: {{reason}}',
                'variables' => json_encode(['payment_number', 'amount', 'currency', 'reason']),
            ],
            [
                'template_key' => 'booking_scheduled',
                'name' => 'Booking Scheduled',
                'type' => 'booking',
                'title' => 'Booking Scheduled',
                'message' => 'Your booking {{booking_number}} has been scheduled for {{scheduled_start_at}}.',
                'variables' => json_encode(['booking_number', 'scheduled_start_at']),
            ],
            [
                'template_key' => 'booking_completed',
                'name' => 'Booking Completed',
                'type' => 'booking',
                'title' => 'Booking Completed',
                'message' => 'Your booking {{booking_number}} has been completed. Thank you for choosing us.',
                'variables' => json_encode(['booking_number']),
            ],
            [
                'template_key' => 'booking_cancelled',
                'name' => 'Booking Cancelled',
                'type' => 'booking',
                'title' => 'Booking Cancelled',
                'message' => 'Your booking {{booking_number}} has been cancelled.',
                'variables' => json_encode(['booking_number']),
            ],
        ];
    }
};
