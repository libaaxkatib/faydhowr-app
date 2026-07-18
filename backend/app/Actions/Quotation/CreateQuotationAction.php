<?php

namespace App\Actions\Quotation;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\QuotationStatus;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use App\Models\Upload;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a quotation request in Draft (Sprint 28). Customers never submit
 * pricing; the permanent quotation number is assigned here and never changes.
 */
class CreateQuotationAction
{
    public function __construct(
        private DashboardCacheInvalidatorInterface $dashboardCache,
        private ResolveQuotationUploadsAction $resolveUploads,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(CustomerProfile $profile, array $attributes): Quotation
    {
        $quotation = DB::transaction(function () use ($profile, $attributes): Quotation {
            $profile = CustomerProfile::query()
                ->whereKey($profile)
                ->lockForUpdate()
                ->firstOrFail();

            $booking = $this->bookingForProfile($profile, $attributes['booking_id'] ?? null);

            $quotation = Quotation::query()->create([
                'quotation_number' => $this->nextQuotationNumber(),
                'customer_profile_id' => $profile->id,
                'booking_id' => $booking?->id,
                'requirements' => $attributes['requirements'],
                'description' => $attributes['description'] ?? null,
                'preferred_timing' => $attributes['preferred_timing'] ?? null,
                'quantity_hint' => $attributes['quantity_hint'] ?? null,
                'status' => QuotationStatus::Draft,
                'subtotal' => '0.00',
                'discount_amount' => '0.00',
                'tax_amount' => '0.00',
                'total_amount' => '0.00',
            ]);

            $this->resolveUploads
                ->handle($profile, $attributes['attachment_ids'] ?? [])
                ->each(function (Upload $upload) use ($quotation): void {
                    $quotation->attachments()->create(['upload_id' => $upload->id]);
                    $upload->update(['attached_at' => now()]);
                });

            $quotation->statusHistories()->create([
                'status' => QuotationStatus::Draft,
                'changed_by_type' => 'user',
                'changed_by_id' => $profile->user_id,
                'notes' => null,
            ]);

            return $quotation->load(['booking', 'attachments.upload']);
        });

        $this->dashboardCache->invalidate();

        return $quotation;
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
}
