<?php

namespace Database\Factories;

use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerAddress>
 */
class CustomerAddressFactory extends Factory
{
    protected $model = CustomerAddress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_profile_id' => CustomerProfile::factory(),
            'label' => fake()->randomElement(['Home', 'Office']),
            'contact_name' => fake()->name(),
            'phone' => fake()->e164PhoneNumber(),
            'line1' => fake()->streetAddress(),
            'line2' => null,
            'city' => fake()->city(),
            'state_region' => null,
            'district' => null,
            'postal_code' => null,
            'country_code' => 'SO',
            'latitude' => null,
            'longitude' => null,
            'is_default' => false,
            'is_active' => true,
        ];
    }
}
