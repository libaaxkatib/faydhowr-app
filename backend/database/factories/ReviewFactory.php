<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Enums\Review\ReviewStatus;
use App\Enums\ServiceMode;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_profile_id' => CustomerProfile::factory(),
            'booking_id' => null,
            'service_id' => null,
            'rating' => fake()->numberBetween(1, 5),
            'title' => fake()->optional()->sentence(3),
            'comment' => fake()->optional()->sentence(12),
            'status' => ReviewStatus::Pending,
        ];
    }

    /**
     * Provision the owning completed booking (and its service) when the
     * test does not supply them explicitly.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Review $review): void {
            if ($review->booking_id !== null && $review->service_id !== null) {
                return;
            }

            $service = $review->service_id !== null
                ? Service::query()->findOrFail($review->service_id)
                : self::createService();

            if ($review->booking_id === null) {
                $booking = self::createCompletedBooking(
                    (int) $review->customer_profile_id,
                    $service,
                );
                $review->booking_id = $booking->id;
            }

            $review->service_id ??= $service->id;
        });
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => ReviewStatus::Published]);
    }

    public function hidden(): static
    {
        return $this->state(fn (): array => ['status' => ReviewStatus::Hidden]);
    }

    private static function createService(): Service
    {
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

        ServiceModeOption::query()->create([
            'service_id' => $service->id,
            'mode' => ServiceMode::OneTime,
            'is_active' => true,
        ]);

        return $service;
    }

    private static function createCompletedBooking(int $customerProfileId, Service $service): Booking
    {
        $mode = ServiceModeOption::query()
            ->where('service_id', $service->id)
            ->firstOrFail();

        return Booking::query()->create([
            'booking_number' => sprintf('BK-%s-%06d', now()->format('Y'), fake()->unique()->numberBetween(1, 999999)),
            'customer_profile_id' => $customerProfileId,
            'service_id' => $service->id,
            'service_mode_id' => $mode->id,
            'status' => BookingStatus::Completed,
            'requested_date' => now()->subWeek()->toDateString(),
            'requested_time_window' => '09:00-12:00',
            'address_snapshot' => ['city' => 'Mogadishu'],
        ]);
    }
}
