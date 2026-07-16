<?php

namespace App\Actions\Quotation;

use App\Enums\QuotationStatus;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateQuotationAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(CustomerProfile $profile, array $attributes): Quotation
    {
        return DB::transaction(function () use ($profile, $attributes): Quotation {
            $profile = CustomerProfile::query()
                ->whereKey($profile)
                ->lockForUpdate()
                ->firstOrFail();

            $booking = $this->bookingForProfile($profile, $attributes['booking_id'] ?? null);

            if (! $this->hasValidTotal($attributes)) {
                throw new DomainException('The total amount must equal subtotal minus discount plus tax.');
            }

            $quotation = Quotation::query()->create([
                'quotation_number' => $this->nextQuotationNumber(),
                'customer_profile_id' => $profile->id,
                'booking_id' => $booking?->id,
                'status' => QuotationStatus::Draft,
                'currency' => $attributes['currency'],
                'subtotal' => $attributes['subtotal'],
                'discount_amount' => $attributes['discount_amount'] ?? 0,
                'tax_amount' => $attributes['tax_amount'] ?? 0,
                'total_amount' => $attributes['total_amount'],
                'valid_until' => $attributes['valid_until'] ?? null,
                'notes' => $attributes['notes'] ?? null,
            ]);

            $quotation->statusHistories()->create([
                'status' => QuotationStatus::Draft,
                'changed_by_type' => 'user',
                'changed_by_id' => $profile->user_id,
                'notes' => null,
            ]);

            return $quotation->load('booking');
        });
    }

    private function bookingForProfile(CustomerProfile $profile, ?int $bookingId): ?Booking
    {
        if ($bookingId === null) {
            return null;
        }

        return $profile
            ->bookings()
            ->whereKey($bookingId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function hasValidTotal(array $attributes): bool
    {
        $subtotal = $this->toCents($attributes['subtotal']);
        $discountAmount = $this->toCents($attributes['discount_amount'] ?? 0);
        $taxAmount = $this->toCents($attributes['tax_amount'] ?? 0);
        $totalAmount = $this->toCents($attributes['total_amount']);

        return $discountAmount <= $subtotal
            && $totalAmount === $subtotal - $discountAmount + $taxAmount;
    }

    private function nextQuotationNumber(): string
    {
        $year = now()->format('Y');

        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["quotation-number-{$year}"]);
        }

        $latestQuotationNumber = Quotation::withTrashed()
            ->where('quotation_number', 'like', "QT-{$year}-%")
            ->orderByDesc('quotation_number')
            ->lockForUpdate()
            ->value('quotation_number');

        $nextSequence = $latestQuotationNumber === null
            ? 1
            : ((int) substr($latestQuotationNumber, -6)) + 1;

        if ($nextSequence > 999999) {
            throw new DomainException('The quotation number range for this year is exhausted.');
        }

        return sprintf('QT-%s-%06d', $year, $nextSequence);
    }

    private function toCents(mixed $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
