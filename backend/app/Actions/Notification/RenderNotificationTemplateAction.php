<?php

namespace App\Actions\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;

class RenderNotificationTemplateAction
{
    public function __construct(
        private RenderTranslatedNotificationTemplateAction $renderTranslatedNotificationTemplate,
    ) {}

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
        return $this->renderTranslatedNotificationTemplate->handle(
            $templateKey,
            $variables,
            $language,
        );
    }
}
