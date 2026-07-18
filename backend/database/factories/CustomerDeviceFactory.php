<?php

namespace Database\Factories;

use App\Enums\DevicePlatform;
use App\Models\CustomerDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerDevice>
 */
class CustomerDeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => $this->faker->uuid(),
            'user_id' => User::factory(),
            'platform' => $this->faker->randomElement(DevicePlatform::cases()),
            'push_token' => $this->faker->sha256(),
            'app_version' => '1.0.0',
            'last_seen_at' => now(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
