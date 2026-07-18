<?php

namespace App\Actions\Quotation;

use App\Enums\QuotationStatus;
use App\Exceptions\Quotation\QuotationNotEditableException;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Customer edits to the quotation request are allowed only while `draft`
 * (Sprint 28). Submit freezes the request permanently.
 */
class UpdateQuotationDraftAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(CustomerProfile $profile, int $quotationId, array $attributes): Quotation
    {
        return DB::transaction(function () use ($profile, $quotationId, $attributes): Quotation {
            $quotation = $profile
                ->quotations()
                ->whereKey($quotationId)
                ->lockForUpdate()
                ->first();

            if ($quotation === null) {
                throw new ModelNotFoundException;
            }

            if ($quotation->status !== QuotationStatus::Draft) {
                throw QuotationNotEditableException::make();
            }

            $booking = null;

            if (array_key_exists('booking_id', $attributes)) {
                $booking = $attributes['booking_id'] === null
                    ? null
                    : $profile->bookings()->whereKey($attributes['booking_id'])->firstOrFail();
            }

            $quotation->fill(array_intersect_key($attributes, array_flip([
                'requirements',
                'description',
                'preferred_timing',
                'quantity_hint',
            ])));

            if (array_key_exists('booking_id', $attributes)) {
                $quotation->booking_id = $booking?->id;
            }

            $quotation->save();

            return $quotation->load(['booking', 'attachments.upload']);
        });
    }
}
