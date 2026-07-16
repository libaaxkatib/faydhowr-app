<?php

namespace Tests\Feature\Postgres;

use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderPostgresIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This integration test requires PostgreSQL.');
        }
    }

    public function test_orders_reject_an_unapproved_status(): void
    {
        $this->expectException(QueryException::class);

        DB::table('orders')->insert([
            ...$this->orderAttributes(),
            'status' => 'invalid',
        ]);
    }

    public function test_orders_reject_a_malformed_public_number(): void
    {
        $this->expectException(QueryException::class);

        DB::table('orders')->insert([
            ...$this->orderAttributes(),
            'order_number' => 'INVALID-1',
        ]);
    }

    public function test_orders_reject_invalid_amount_totals(): void
    {
        $this->expectException(QueryException::class);

        DB::table('orders')->insert([
            ...$this->orderAttributes(),
            'total_amount' => 90,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderAttributes(): array
    {
        $profile = CustomerProfile::factory()->create();
        $quotation = Quotation::query()->create([
            'quotation_number' => sprintf('QT-%s-%06d', now()->format('Y'), fake()->unique()->numberBetween(1, 999999)),
            'customer_profile_id' => $profile->id,
            'status' => QuotationStatus::Accepted,
            'subtotal' => 100,
            'discount_amount' => 10,
            'tax_amount' => 5,
            'total_amount' => 95,
            'valid_until' => now()->addWeek(),
            'accepted_at' => now(),
        ]);

        return [
            'order_number' => sprintf('ORD-%s-%06d', now()->format('Y'), fake()->unique()->numberBetween(1, 999999)),
            'customer_profile_id' => $profile->id,
            'quotation_id' => $quotation->id,
            'status' => 'pending_payment',
            'subtotal' => 100,
            'discount_amount' => 10,
            'tax_amount' => 5,
            'total_amount' => 95,
            'notes' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
