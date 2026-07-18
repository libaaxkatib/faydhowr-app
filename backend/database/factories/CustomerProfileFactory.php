<?php

namespace Database\Factories;

use App\Enums\Customer\CustomerStatus;
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
            'customer_number' => sprintf('CUS-%06d', fake()->unique()->numberBetween(1, 999999)),
            'full_name' => fake()->name(),
            'avatar_url' => null,
            'gender' => null,
            'date_of_birth' => null,
            'preferred_language' => 'so',
            'status' => CustomerStatus::Active,
            'tags' => null,
            'notification_preferences' => null,
            'classification' => 'lead',
        ];
    }

    public function activeCustomer(): static
    {
        return $this->state(fn (): array => ['classification' => 'active_customer']);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => CustomerStatus::Inactive]);
    }

    public function blocked(): static
    {
        return $this->state(fn (): array => ['status' => CustomerStatus::Blocked]);
    }
}
