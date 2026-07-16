<?php

namespace Database\Factories;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'po_number' => sprintf(
                'PO-%s-%06d',
                now()->format('Y'),
                fake()->unique()->numberBetween(1, 999999),
            ),
            'supplier_id' => Supplier::factory(),
            'status' => PurchaseOrderStatus::Draft,
            'currency' => 'USD',
            'subtotal' => 0,
            'notes' => null,
            'submitted_at' => null,
            'approved_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (): array => [
            'status' => PurchaseOrderStatus::Submitted,
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => PurchaseOrderStatus::Approved,
            'submitted_at' => now()->subDay(),
            'approved_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => PurchaseOrderStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
