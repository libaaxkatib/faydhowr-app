<?php

namespace App\Actions\Notification;

use App\Models\NotificationTemplate;

class UpdateNotificationTemplateAction
{
    /**
     * @param  array{
     *     name?: string,
     *     type?: string,
     *     channel?: string,
     *     language?: string,
     *     subject?: string|null,
     *     title?: string,
     *     message?: string,
     *     status?: string,
     *     variables?: list<string>|null
     * }  $data
     */
    public function handle(NotificationTemplate $template, array $data): NotificationTemplate
    {
        $template->fill($data);
        $template->save();

        return $template->refresh();
    }
}
