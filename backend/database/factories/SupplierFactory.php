<?php

namespace Database\Factories;

use App\Enums\SupplierStatus;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'contact_person' => fake()->name(),
            'phone' => fake()->e164PhoneNumber(),
            'email' => fake()->unique()->companyEmail(),
            'address' => fake()->address(),
            'status' => SupplierStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => SupplierStatus::Inactive,
        ]);
    }
}
