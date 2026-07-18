<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seeds the six mandatory Sprint 28 quotation workflow notification
     * templates (API Design §13.7 / §18.10). Existing keys are left untouched
     * so admin edits are preserved.
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
                'template_key' => 'quotation_submitted',
                'name' => 'Quotation Submitted',
                'type' => 'quotation',
                'title' => 'Quotation Request Submitted',
                'message' => 'Quotation request {{quotation_number}} has been submitted and is awaiting review.',
                'variables' => json_encode(['quotation_number']),
            ],
            [
                'template_key' => 'quotation_issued',
                'name' => 'Quotation Issued',
                'type' => 'quotation',
                'title' => 'Quotation Issued',
                'message' => 'Your quotation {{quotation_number}} (Version {{version_number}}) is ready for review.',
                'variables' => json_encode(['quotation_number', 'version_number']),
            ],
            [
                'template_key' => 'quotation_revised',
                'name' => 'Quotation Revised',
                'type' => 'quotation',
                'title' => 'Quotation Updated',
                'message' => 'Your quotation {{quotation_number}} has been updated. Please review Version {{version_number}}.',
                'variables' => json_encode(['quotation_number', 'version_number']),
            ],
            [
                'template_key' => 'quotation_discussion_reply',
                'name' => 'Quotation Discussion Reply',
                'type' => 'quotation',
                'title' => 'New Discussion Message',
                'message' => 'A new message was posted on quotation {{quotation_number}}.',
                'variables' => json_encode(['quotation_number']),
            ],
            [
                'template_key' => 'quotation_expired',
                'name' => 'Quotation Expired',
                'type' => 'quotation',
                'title' => 'Quotation Expired',
                'message' => 'Your quotation {{quotation_number}} has expired. Contact us to request an updated offer.',
                'variables' => json_encode(['quotation_number']),
            ],
            [
                'template_key' => 'quotation_cancelled',
                'name' => 'Quotation Cancelled',
                'type' => 'quotation',
                'title' => 'Quotation Cancelled',
                'message' => 'Your quotation {{quotation_number}} has been cancelled.',
                'variables' => json_encode(['quotation_number']),
            ],
        ];
    }
};
