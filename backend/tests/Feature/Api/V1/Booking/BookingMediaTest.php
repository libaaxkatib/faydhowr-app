<?php

namespace Tests\Feature\Api\V1\Booking;

use App\Enums\BookingMediaType;
use App\Enums\BookingStatus;
use App\Enums\ServiceMode;
use App\Models\Booking;
use App\Models\BookingMedia;
use App\Models\CustomerProfile;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_media_relationships_are_defined(): void
    {
        $booking = $this->createBooking();
        $media = BookingMedia::query()->create([
            'booking_id' => $booking->id,
            'media_type' => BookingMediaType::Image,
            'disk' => 'private',
            'path' => 'bookings/example.jpg',
            'original_name' => 'example.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'sort_order' => 0,
            'uploaded_at' => now(),
        ]);

        self::assertTrue($media->booking->is($booking));
        self::assertTrue($booking->media->contains($media));
    }

    public function test_booking_media_type_is_cast_to_the_enum(): void
    {
        $media = BookingMedia::query()->create([
            'booking_id' => $this->createBooking()->id,
            'media_type' => BookingMediaType::Video,
            'disk' => 'private',
            'path' => 'bookings/example.mp4',
            'original_name' => 'example.mp4',
            'mime_type' => 'video/mp4',
            'file_size' => 2048,
            'sort_order' => 1,
            'uploaded_at' => now(),
        ])->refresh();

        self::assertInstanceOf(BookingMediaType::class, $media->media_type);
        self::assertSame(BookingMediaType::Video, $media->media_type);
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
