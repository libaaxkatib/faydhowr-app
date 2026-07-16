<?php

namespace Tests\Feature\Postgres;

use App\Models\CustomerProfile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuotationPostgresIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This integration test requires PostgreSQL.');
        }
    }

    public function test_quotations_reject_an_unapproved_status(): void
    {
        $this->expectException(QueryException::class);

        DB::table('quotations')->insert([
            ...$this->quotationAttributes(),
            'status' => 'invalid',
        ]);
    }

    public function test_quotations_reject_a_malformed_public_number(): void
    {
        $this->expectException(QueryException::class);

        DB::table('quotations')->insert([
            ...$this->quotationAttributes(),
            'quotation_number' => 'INVALID-1',
        ]);
    }

    public function test_quotations_reject_invalid_amount_totals(): void
    {
        $this->expectException(QueryException::class);

        DB::table('quotations')->insert([
            ...$this->quotationAttributes(),
            'total_amount' => 90,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function quotationAttributes(): array
    {
        $profile = CustomerProfile::factory()->create();

        return [
            'quotation_number' => sprintf('QT-%s-%06d', now()->format('Y'), fake()->unique()->numberBetween(1, 999999)),
            'customer_profile_id' => $profile->id,
            'booking_id' => null,
            'status' => 'pending_review',
            'subtotal' => 100,
            'discount_amount' => 10,
            'tax_amount' => 5,
            'total_amount' => 95,
            'valid_until' => now()->addWeek(),
            'accepted_at' => null,
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
