<?php

namespace Database\Factories;

use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerProfile>
 */
class CustomerProfileFactory extends Factory
{
    protected $model = CustomerProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'customer_number' => sprintf(
                'CUS-%s-%06d',
                now()->format('Y'),
                fake()->unique()->numberBetween(1, 999999),
            ),
            'full_name' => fake()->name(),
            'avatar_url' => null,
            'preferred_language' => 'so',
            'notification_preferences' => null,
            'classification' => 'lead',
        ];
    }

    public function activeCustomer(): static
    {
        return $this->state(fn (): array => ['classification' => 'active_customer']);
    }
}
