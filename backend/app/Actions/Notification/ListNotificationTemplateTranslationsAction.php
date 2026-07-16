<?php

namespace App\Actions\Notification;

use App\Models\NotificationTemplate;
use App\Models\NotificationTemplateTranslation;
use Illuminate\Database\Eloquent\Collection;

class ListNotificationTemplateTranslationsAction
{
    /**
     * @return Collection<int, NotificationTemplateTranslation>
     */
    public function handle(NotificationTemplate $template): Collection
    {
        return $template->translations()
            ->orderBy('language')
            ->get();
    }
}
