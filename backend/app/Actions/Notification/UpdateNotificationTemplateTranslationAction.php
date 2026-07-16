<?php

namespace App\Actions\Notification;

use App\Models\NotificationTemplate;
use App\Models\NotificationTemplateTranslation;
use DomainException;

class UpdateNotificationTemplateTranslationAction
{
    /**
     * @param  array{
     *     language?: string,
     *     subject?: string|null,
     *     title?: string,
     *     message?: string
     * }  $data
     */
    public function handle(
        NotificationTemplate $template,
        NotificationTemplateTranslation $translation,
        array $data,
    ): NotificationTemplateTranslation {
        if ($translation->notification_template_id !== $template->id) {
            throw new DomainException('TRANSLATION_NOT_FOUND');
        }

        if (array_key_exists('language', $data) && $data['language'] !== $translation->language) {
            $exists = $template->translations()
                ->where('language', $data['language'])
                ->whereKeyNot($translation->id)
                ->exists();

            if ($exists) {
                throw new DomainException('TRANSLATION_LANGUAGE_EXISTS');
            }
        }

        $translation->fill($data);
        $translation->save();

        return $translation->refresh();
    }
}
