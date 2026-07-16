<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'category_id' => ProductCategory::factory(),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####??')),
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'description' => fake()->paragraph(),
            'selling_price' => fake()->randomFloat(2, 1, 500),
            'cost_price' => fake()->randomFloat(2, 0.5, 250),
            'currency' => 'USD',
            'current_stock' => fake()->numberBetween(0, 100),
            'low_stock_threshold' => fake()->numberBetween(0, 10),
            'status' => ProductStatus::Active,
            'is_featured' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::Inactive,
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn (): array => [
            'is_featured' => true,
        ]);
    }
}
