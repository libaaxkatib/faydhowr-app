<?php

namespace Database\Factories;

use App\Enums\NotificationArchiveStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Admin;
use App\Models\ArchivedNotification;
use App\Models\CustomerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArchivedNotification>
 */
class ArchivedNotificationFactory extends Factory
{
    protected $model = ArchivedNotification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'original_notification_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'recipient_type' => Admin::class,
            'recipient_id' => Admin::factory(),
            'type' => NotificationType::System,
            'channel' => NotificationChannel::InApp,
            'status' => NotificationArchiveStatus::Read,
            'title' => fake()->sentence(4),
            'message' => fake()->sentence(12),
            'data' => null,
            'processing_started_at' => now()->subMinutes(5),
            'sent_at' => now()->subMinutes(4),
            'delivered_at' => now()->subMinutes(3),
            'read_at' => now()->subMinutes(2),
            'failed_at' => null,
            'archived_at' => now(),
            'created_at' => now()->subDay(),
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

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationArchiveStatus::Failed,
            'delivered_at' => null,
            'read_at' => null,
            'failed_at' => now()->subMinute(),
        ]);
    }
}
