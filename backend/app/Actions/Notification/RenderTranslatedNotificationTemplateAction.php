<?php

namespace App\Actions\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationTemplateStatus;
use App\Enums\NotificationType;
use App\Models\NotificationTemplate;
use App\Models\NotificationTemplateTranslation;
use DomainException;

class RenderTranslatedNotificationTemplateAction
{
    /**
     * @param  array<string, mixed>  $variables
     * @return array{
     *     template_key: string,
     *     type: NotificationType,
     *     channel: NotificationChannel,
     *     language: string,
     *     subject: ?string,
     *     title: string,
     *     message: string
     * }
     */
    public function handle(string $templateKey, array $variables = [], ?string $language = null): array
    {
        $template = NotificationTemplate::query()
            ->where('template_key', $templateKey)
            ->with('translations')
            ->first();

        if ($template === null) {
            throw new DomainException('TEMPLATE_NOT_FOUND');
        }

        if ($template->status !== NotificationTemplateStatus::Active) {
            throw new DomainException('TEMPLATE_INACTIVE');
        }

        $requestedLanguage = $language ?: $template->language;
        $content = $this->resolveContent($template, $requestedLanguage);

        return [
            'template_key' => $template->template_key,
            'type' => $template->type,
            'channel' => $template->channel,
            'language' => $content['language'],
            'subject' => $content['subject'] === null
                ? null
                : $this->replacePlaceholders($content['subject'], $variables),
            'title' => $this->replacePlaceholders($content['title'], $variables),
            'message' => $this->replacePlaceholders($content['message'], $variables),
        ];
    }

    /**
     * @return array{language: string, subject: ?string, title: string, message: string}
     */
    private function resolveContent(NotificationTemplate $template, string $requestedLanguage): array
    {
        $translation = $this->findTranslation($template, $requestedLanguage);

        if ($translation === null && $requestedLanguage !== $template->language) {
            $translation = $this->findTranslation($template, $template->language);
        }

        if ($translation === null && $requestedLanguage !== 'en' && $template->language !== 'en') {
            $translation = $this->findTranslation($template, 'en');
        }

        if ($translation !== null) {
            return [
                'language' => $translation->language,
                'subject' => $translation->subject,
                'title' => $translation->title,
                'message' => $translation->message,
            ];
        }

        return [
            'language' => $template->language,
            'subject' => $template->subject,
            'title' => $template->title,
            'message' => $template->message,
        ];
    }

    private function findTranslation(NotificationTemplate $template, string $language): ?NotificationTemplateTranslation
    {
        return $template->translations->first(
            fn (NotificationTemplateTranslation $translation): bool => $translation->language === $language,
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function replacePlaceholders(string $content, array $variables): string
    {
        $replaced = preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            function (array $matches) use ($variables): string {
                $key = $matches[1];

                if (! array_key_exists($key, $variables)) {
                    return $matches[0];
                }

                $value = $variables[$key];

                if (is_bool($value)) {
                    return $value ? '1' : '0';
                }

                if (is_scalar($value) || $value === null) {
                    return (string) $value;
                }

                return $matches[0];
            },
            $content,
        );

        return $replaced ?? $content;
    }
}
