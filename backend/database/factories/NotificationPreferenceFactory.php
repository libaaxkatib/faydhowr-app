<?php

namespace Database\Factories;

use App\Enums\NotificationType;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\NotificationPreference;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipient_type' => Admin::class,
            'recipient_id' => Admin::factory(),
            'notification_type' => NotificationType::Payment,
            'in_app' => true,
            'email' => true,
            'sms' => false,
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

    public function forRecipient(Model $recipient): static
    {
        return $this->state(fn (): array => [
            'recipient_type' => $recipient::class,
            'recipient_id' => $recipient->getKey(),
        ]);
    }
}
