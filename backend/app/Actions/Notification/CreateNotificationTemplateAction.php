<?php

namespace App\Actions\Notification;

use App\Models\NotificationTemplate;

class CreateNotificationTemplateAction
{
    /**
     * @param  array{
     *     template_key: string,
     *     name: string,
     *     type: string,
     *     channel: string,
     *     language?: string,
     *     subject?: string|null,
     *     title: string,
     *     message: string,
     *     status?: string,
     *     variables?: list<string>|null
     * }  $data
     */
    public function handle(array $data): NotificationTemplate
    {
        return NotificationTemplate::query()->create([
            'template_key' => $data['template_key'],
            'name' => $data['name'],
            'type' => $data['type'],
            'channel' => $data['channel'],
            'language' => $data['language'] ?? 'en',
            'subject' => $data['subject'] ?? null,
            'title' => $data['title'],
            'message' => $data['message'],
            'status' => $data['status'] ?? 'active',
            'variables' => $data['variables'] ?? null,
        ]);
    }
}
