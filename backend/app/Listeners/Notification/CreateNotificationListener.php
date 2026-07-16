<?php

namespace App\Listeners\Notification;

use App\Actions\Notification\CreateNotificationAction;
use App\Actions\Notification\DispatchNotificationJobAction;
use App\Actions\Notification\RenderTranslatedNotificationTemplateAction;
use App\Actions\Notification\ResolveNotificationChannelsAction;
use App\Enums\NotificationChannel;
use App\Events\Notification\NotificationRequested;
use App\Models\CustomerProfile;

class CreateNotificationListener
{
    public function __construct(
        private RenderTranslatedNotificationTemplateAction $renderTranslatedNotificationTemplate,
        private ResolveNotificationChannelsAction $resolveNotificationChannels,
        private CreateNotificationAction $createNotification,
        private DispatchNotificationJobAction $dispatchNotificationJob,
    ) {}

    public function handle(NotificationRequested $event): void
    {
        $language = $event->language;

        if ($language === null && $event->recipient instanceof CustomerProfile) {
            $language = $event->recipient->preferred_language;
        }

        $rendered = $this->renderTranslatedNotificationTemplate->handle(
            $event->templateKey,
            $event->variables,
            $language,
        );

        $allowedChannels = $this->resolveNotificationChannels->handle(
            $event->recipient,
            $rendered['type'],
        );

        $channels = array_values(array_filter(
            [$rendered['channel']],
            fn (NotificationChannel $channel): bool => in_array($channel, $allowedChannels, true),
        ));

        if ($channels === []) {
            return;
        }

        $data = $event->data ?? [];
        $data['template_key'] = $rendered['template_key'];
        $data['variables'] = $event->variables;
        $data['language'] = $rendered['language'];

        if ($rendered['subject'] !== null) {
            $data['subject'] = $rendered['subject'];
        }

        $created = $this->createNotification->handle(
            recipient: $event->recipient,
            type: $rendered['type'],
            channels: $channels,
            title: $rendered['title'],
            message: $rendered['message'],
            eventId: $event->eventId,
            data: $data,
        );

        foreach ($created as $notification) {
            $this->dispatchNotificationJob->handle($notification);
        }
    }
}
