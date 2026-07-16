<?php

namespace Tests\Feature\Postgres;

use App\Enums\ServiceMode;
use App\Models\CustomerProfile;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingPostgresIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This integration test requires PostgreSQL.');
        }
    }

    public function test_bookings_reject_an_unapproved_status(): void
    {
        $this->expectException(QueryException::class);

        DB::table('bookings')->insert([
            ...$this->bookingAttributes(),
            'status' => 'invalid',
        ]);
    }

    public function test_bookings_reject_a_malformed_public_number(): void
    {
        $this->expectException(QueryException::class);

        DB::table('bookings')->insert([
            ...$this->bookingAttributes(),
            'booking_number' => 'INVALID-1',
        ]);
    }

    public function test_bookings_reject_an_invalid_confirmed_schedule_range(): void
    {
        $this->expectException(QueryException::class);

        $start = now()->addWeek();

        DB::table('bookings')->insert([
            ...$this->bookingAttributes(),
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $start->copy(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingAttributes(): array
    {
        $profile = CustomerProfile::factory()->create();
        $category = ServiceCategory::query()->create([
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $service = Service::query()->create([
            'category_id' => $category->id,
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'currency' => 'USD',
            'requires_address' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $mode = ServiceModeOption::query()->create([
            'service_id' => $service->id,
            'mode' => ServiceMode::OneTime,
            'is_active' => true,
        ]);

        return [
            'booking_number' => sprintf('BK-%s-%06d', now()->format('Y'), fake()->unique()->numberBetween(1, 999999)),
            'customer_profile_id' => $profile->id,
            'service_id' => $service->id,
            'service_mode_id' => $mode->id,
            'status' => 'submitted',
            'requested_date' => now()->addWeek()->toDateString(),
            'requested_time_window' => '09:00-12:00',
            'address_snapshot' => json_encode([
                'line1' => 'KM4 Road',
                'city' => 'Mogadishu',
                'country_code' => 'SO',
            ], JSON_THROW_ON_ERROR),
            'customer_notes' => null,
            'scheduled_start_at' => null,
            'scheduled_end_at' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
