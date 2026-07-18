<?php

namespace App\Http\Resources\Api\V1\Quotation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Standard quotation payload (API Design §9.4A): `quotation_number`,
 * `latest_version`, `status`, `can_accept`, and `can_discuss` form the
 * authoritative core. The business flags are computed only by the server;
 * clients must never re-derive them.
 */
class QuotationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'quotation_number' => $this->quotation_number,
            'latest_version' => $this->latestRevision?->version_number,
            'status' => $this->status->value,
            'can_accept' => $this->canAccept(),
            'can_discuss' => $this->canDiscuss(),
            'booking' => $this->whenLoaded('booking', fn (): ?array => $this->booking === null ? null : [
                'booking_number' => $this->booking->booking_number,
                'status' => $this->booking->status->value,
                'requested_date' => $this->booking->requested_date?->toDateString(),
                'requested_time_window' => $this->booking->requested_time_window,
            ]),
            'requirements' => $this->requirements,
            'description' => $this->description,
            'preferred_timing' => $this->preferred_timing,
            'quantity_hint' => $this->quantity_hint,
            'currency' => $this->currency,
            'latest_revision' => $this->whenLoaded(
                'latestRevision',
                fn (): ?QuotationRevisionResource => $this->latestRevision === null
                    ? null
                    : new QuotationRevisionResource($this->latestRevision),
            ),
            'attachments' => $this->whenLoaded(
                'attachments',
                fn (): mixed => QuotationAttachmentResource::collection($this->attachments),
            ),
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'payment_type' => $this->payment_type?->value,
            'deposit_percentage' => $this->deposit_percentage,
            'deposit_amount' => $this->deposit_amount,
            'remaining_amount' => $this->remaining_amount,
            'valid_until' => $this->valid_until?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'accepted_at' => $this->accepted_at?->toISOString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
