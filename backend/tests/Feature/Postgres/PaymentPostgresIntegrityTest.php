<?php

namespace Tests\Feature\Postgres;

use App\Enums\OrderStatus;
use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Quotation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentPostgresIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This integration test requires PostgreSQL.');
        }
    }

    public function test_payments_reject_an_unapproved_status(): void
    {
        $this->expectException(QueryException::class);

        DB::table('payments')->insert([
            ...$this->paymentAttributes(),
            'status' => 'invalid',
        ]);
    }

    public function test_payments_reject_a_malformed_public_number(): void
    {
        $this->expectException(QueryException::class);

        DB::table('payments')->insert([
            ...$this->paymentAttributes(),
            'payment_number' => 'INVALID-1',
        ]);
    }

    public function test_payments_reject_duplicate_public_numbers(): void
    {
        $attributes = $this->paymentAttributes();

        DB::table('payments')->insert($attributes);

        $this->expectException(QueryException::class);

        DB::table('payments')->insert([
            ...$attributes,
            'payable_id' => $attributes['payable_id'] + 1,
        ]);
    }

    public function test_payments_reject_non_positive_amounts(): void
    {
        $this->expectException(QueryException::class);

        DB::table('payments')->insert([
            ...$this->paymentAttributes(),
            'amount' => 0,
        ]);
    }

    public function test_payments_reject_a_malformed_receipt_number(): void
    {
        $this->expectException(QueryException::class);

        DB::table('payments')->insert([
            ...$this->paymentAttributes(),
            'status' => 'paid',
            'receipt_number' => 'INVALID-RCPT',
            'paid_at' => now(),
        ]);
    }

    public function test_payments_reject_duplicate_receipt_numbers(): void
    {
        $firstAttributes = $this->paymentAttributes();
        $secondAttributes = $this->paymentAttributes();

        DB::table('payments')->insert([
            ...$firstAttributes,
            'status' => 'paid',
            'receipt_number' => 'RCPT-2026-000001',
            'paid_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('payments')->insert([
            ...$secondAttributes,
            'status' => 'paid',
            'receipt_number' => 'RCPT-2026-000001',
            'paid_at' => now(),
        ]);
    }

    public function test_payments_reject_multiple_active_records_for_the_same_payable(): void
    {
        $attributes = $this->paymentAttributes();

        DB::table('payments')->insert($attributes);

        $this->expectException(QueryException::class);

        DB::table('payments')->insert([
            ...$attributes,
            'payment_number' => sprintf('PAY-%s-%06d', now()->format('Y'), fake()->unique()->numberBetween(2, 999999)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentAttributes(): array
    {
        $profile = CustomerProfile::factory()->create();
        $order = $this->createOrder($profile);

        return [
            'payment_number' => sprintf('PAY-%s-%06d', now()->format('Y'), fake()->unique()->numberBetween(1, 999999)),
            'receipt_number' => null,
            'customer_profile_id' => $profile->id,
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'status' => 'initialized',
            'amount' => 95,
            'currency' => 'USD',
            'gateway' => 'manual',
            'gateway_reference' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ];
    }

    private function createOrder(CustomerProfile $profile): Order
    {
        $quotation = Quotation::query()->create([
            'quotation_number' => sprintf('QT-%s-%06d', now()->format('Y'), fake()->unique()->numberBetween(1, 999999)),
            'customer_profile_id' => $profile->id,
            'status' => QuotationStatus::Accepted,
            'currency' => 'USD',
            'subtotal' => '100.00',
            'discount_amount' => '10.00',
            'tax_amount' => '5.00',
            'total_amount' => '95.00',
            'valid_until' => now()->addWeek(),
            'accepted_at' => now(),
        ]);

        return Order::query()->create([
            'order_number' => sprintf('ORD-%s-%06d', now()->format('Y'), fake()->unique()->numberBetween(1, 999999)),
            'customer_profile_id' => $profile->id,
            'quotation_id' => $quotation->id,
            'status' => OrderStatus::PendingPayment,
            'currency' => $quotation->currency,
            'subtotal' => $quotation->subtotal,
            'discount_amount' => $quotation->discount_amount,
            'tax_amount' => $quotation->tax_amount,
            'total_amount' => $quotation->total_amount,
        ]);
    }
}
