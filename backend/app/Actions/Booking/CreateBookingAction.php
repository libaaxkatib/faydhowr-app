<?php

namespace App\Actions\Booking;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Models\Service;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateBookingAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(CustomerProfile $profile, array $attributes): Booking
    {
        $booking = DB::transaction(function () use ($profile, $attributes): Booking {
            $profile = CustomerProfile::query()
                ->whereKey($profile)
                ->lockForUpdate()
                ->firstOrFail();

            $service = Service::query()
                ->whereKey($attributes['service_id'])
                ->where('is_active', true)
                ->first();

            if ($service === null) {
                throw new DomainException('The selected service is unavailable.');
            }

            $serviceModeIsAvailable = $service
                ->modes()
                ->whereKey($attributes['service_mode_id'])
                ->where('is_active', true)
                ->exists();

            if (! $serviceModeIsAvailable) {
                throw new DomainException('The selected booking mode is unavailable for this service.');
            }

            $address = $profile
                ->addresses()
                ->whereKey($attributes['customer_address_id'])
                ->where('is_active', true)
                ->firstOrFail();

            $booking = $profile->bookings()->create([
                'booking_number' => $this->nextBookingNumber(),
                'service_id' => $service->id,
                'service_mode_id' => $attributes['service_mode_id'],
                'status' => BookingStatus::Submitted,
                'requested_date' => $attributes['requested_date'],
                'requested_time_window' => $attributes['requested_time_window'],
                'address_snapshot' => $this->addressSnapshot($address),
                'customer_notes' => $attributes['customer_notes'] ?? null,
            ]);

            $booking->statusHistories()->create([
                'status' => BookingStatus::Submitted,
                'changed_by_type' => 'user',
                'changed_by_id' => $profile->user_id,
                'notes' => null,
            ]);

            return $booking->load(['service', 'serviceMode']);
        });

        $this->dashboardCache->invalidate();

        return $booking;
    }

    private function nextBookingNumber(): string
    {
        $year = now()->format('Y');

        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["booking-number-{$year}"]);
        }

        $latestBookingNumber = Booking::withTrashed()
            ->where('booking_number', 'like', "BK-{$year}-%")
            ->orderByDesc('booking_number')
            ->lockForUpdate()
            ->value('booking_number');

        $nextSequence = $latestBookingNumber === null
            ? 1
            : ((int) substr($latestBookingNumber, -6)) + 1;

        if ($nextSequence > 999999) {
            throw new DomainException('The booking number range for this year is exhausted.');
        }

        return sprintf('BK-%s-%06d', $year, $nextSequence);
    }

    /**
     * @return array<string, mixed>
     */
    private function addressSnapshot(CustomerAddress $address): array
    {
        return [
            'source_address_id' => $address->id,
            'label' => $address->label,
            'contact_name' => $address->contact_name,
            'phone' => $address->phone,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'city' => $address->city,
            'state_region' => $address->state_region,
            'postal_code' => $address->postal_code,
            'country_code' => $address->country_code,
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
        ];
    }
}
