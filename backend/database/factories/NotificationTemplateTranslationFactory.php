<?php

namespace Database\Factories;

use App\Models\NotificationTemplate;
use App\Models\NotificationTemplateTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationTemplateTranslation>
 */
class NotificationTemplateTranslationFactory extends Factory
{
    protected $model = NotificationTemplateTranslation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_template_id' => NotificationTemplate::factory(),
            'language' => 'so',
            'subject' => null,
            'title' => 'Salaan {{customer_name}}',
            'message' => 'Tixraacaagu waa {{booking_number}}.',
        ];
    }
}
