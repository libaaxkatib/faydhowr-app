<?php

namespace Database\Factories;

use App\Models\Admin;
use App\Models\CustomerNote;
use App\Models\CustomerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerNote>
 */
class CustomerNoteFactory extends Factory
{
    protected $model = CustomerNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_profile_id' => CustomerProfile::factory(),
            'admin_id' => Admin::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
