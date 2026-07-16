<?php

namespace App\Actions\Notification;

use App\Models\NotificationTemplate;
use App\Models\NotificationTemplateTranslation;
use DomainException;

class CreateNotificationTemplateTranslationAction
{
    /**
     * @param  array{
     *     language: string,
     *     subject?: string|null,
     *     title: string,
     *     message: string
     * }  $data
     */
    public function handle(NotificationTemplate $template, array $data): NotificationTemplateTranslation
    {
        $exists = $template->translations()
            ->where('language', $data['language'])
            ->exists();

        if ($exists) {
            throw new DomainException('TRANSLATION_LANGUAGE_EXISTS');
        }

        return $template->translations()->create([
            'language' => $data['language'],
            'subject' => $data['subject'] ?? null,
            'title' => $data['title'],
            'message' => $data['message'],
        ]);
    }
}
