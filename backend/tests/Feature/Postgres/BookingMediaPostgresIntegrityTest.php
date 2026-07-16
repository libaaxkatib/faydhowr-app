<?php

namespace Tests\Feature\Postgres;

use App\Enums\BookingStatus;
use App\Enums\ServiceMode;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingMediaPostgresIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This integration test requires PostgreSQL.');
        }
    }

    public function test_booking_media_rejects_document_types(): void
    {
        $this->expectException(QueryException::class);

        DB::table('booking_media')->insert([
            'booking_id' => $this->createBooking()->id,
            'media_type' => 'document',
            'disk' => 'private',
            'path' => 'bookings/document.pdf',
            'original_name' => 'document.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'sort_order' => 0,
            'uploaded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_booking_media_rejects_mismatched_media_metadata(): void
    {
        $this->expectException(QueryException::class);

        DB::table('booking_media')->insert([
            'booking_id' => $this->createBooking()->id,
            'media_type' => 'image',
            'disk' => 'private',
            'path' => 'bookings/example.jpg',
            'original_name' => 'example.jpg',
            'mime_type' => 'video/mp4',
            'file_size' => 1024,
            'sort_order' => 0,
            'uploaded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createBooking(): Booking
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

        return Booking::query()->create([
            'booking_number' => sprintf('BK-%s-%06d', now()->format('Y'), fake()->unique()->numberBetween(1, 999999)),
            'customer_profile_id' => $profile->id,
            'service_id' => $service->id,
            'service_mode_id' => $mode->id,
            'status' => BookingStatus::Submitted,
            'requested_date' => now()->addWeek()->toDateString(),
            'requested_time_window' => '09:00-12:00',
            'address_snapshot' => [
                'line1' => 'KM4 Road',
                'city' => 'Mogadishu',
                'country_code' => 'SO',
            ],
        ]);
    }
}
