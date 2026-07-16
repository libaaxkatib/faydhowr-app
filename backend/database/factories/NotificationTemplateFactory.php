<?php

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationTemplateStatus;
use App\Enums\NotificationType;
use App\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationTemplate>
 */
class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'template_key' => fake()->unique()->slug(3),
            'name' => fake()->sentence(3),
            'type' => NotificationType::System,
            'channel' => NotificationChannel::InApp,
            'language' => 'en',
            'subject' => null,
            'title' => 'Hello {{customer_name}}',
            'message' => 'Your reference is {{booking_number}} on {{date}}.',
            'status' => NotificationTemplateStatus::Active,
            'variables' => ['customer_name', 'booking_number', 'date'],
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationTemplateStatus::Inactive,
        ]);
    }

    public function email(): static
    {
        return $this->state(fn (): array => [
            'channel' => NotificationChannel::Email,
            'subject' => 'Update for {{customer_name}}',
        ]);
    }
}
