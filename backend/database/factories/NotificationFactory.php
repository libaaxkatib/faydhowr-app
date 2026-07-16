<?php

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipient_type' => Admin::class,
            'recipient_id' => Admin::factory(),
            'type' => NotificationType::System,
            'channel' => NotificationChannel::InApp,
            'status' => NotificationStatus::Pending,
            'title' => fake()->sentence(4),
            'message' => fake()->sentence(12),
            'data' => null,
            'event_id' => fake()->uuid(),
            'processing_started_at' => null,
            'sent_at' => null,
            'delivered_at' => null,
            'read_at' => null,
            'failed_at' => null,
        ];
    }

    public function forAdmin(?Admin $admin = null): static
    {
        return $this->state(fn (): array => [
            'recipient_type' => Admin::class,
            'recipient_id' => $admin?->id ?? Admin::factory(),
        ]);
    }

    public function forCustomerProfile(?CustomerProfile $profile = null): static
    {
        return $this->state(fn (): array => [
            'recipient_type' => CustomerProfile::class,
            'recipient_id' => $profile?->id ?? CustomerProfile::factory(),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationStatus::Processing,
            'processing_started_at' => now(),
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationStatus::Sent,
            'processing_started_at' => now()->subMinutes(2),
            'sent_at' => now(),
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationStatus::Delivered,
            'processing_started_at' => now()->subMinutes(3),
            'sent_at' => now()->subMinutes(2),
            'delivered_at' => now(),
        ]);
    }

    public function read(): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationStatus::Read,
            'processing_started_at' => now()->subMinutes(4),
            'sent_at' => now()->subMinutes(3),
            'delivered_at' => now()->subMinutes(2),
            'read_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationStatus::Failed,
            'processing_started_at' => now()->subMinute(),
            'failed_at' => now(),
        ]);
    }

    public function forRecipient(Model $recipient): static
    {
        return $this->state(fn (): array => [
            'recipient_type' => $recipient::class,
            'recipient_id' => $recipient->getKey(),
        ]);
    }
}
