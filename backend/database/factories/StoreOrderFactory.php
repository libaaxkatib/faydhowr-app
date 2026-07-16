<?php

namespace Database\Factories;

use App\Enums\StoreOrderStatus;
use App\Models\CustomerProfile;
use App\Models\StoreOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreOrder>
 */
class StoreOrderFactory extends Factory
{
    protected $model = StoreOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_order_number' => sprintf(
                'STO-%s-%06d',
                now()->format('Y'),
                fake()->unique()->numberBetween(1, 999999),
            ),
            'customer_profile_id' => CustomerProfile::factory(),
            'cart_id' => null,
            'customer_address_id' => null,
            'status' => StoreOrderStatus::PendingPayment,
            'currency' => 'USD',
            'total_items' => 1,
            'total_quantity' => 1,
            'subtotal' => 10.00,
            'shipping_address_snapshot' => [
                'label' => 'Home',
                'contact_name' => fake()->name(),
                'phone' => fake()->e164PhoneNumber(),
                'line1' => fake()->streetAddress(),
                'line2' => null,
                'city' => 'Mogadishu',
                'state_region' => null,
                'postal_code' => null,
                'country_code' => 'SO',
            ],
            'notes' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => StoreOrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Customer cancelled',
        ]);
    }
}
