<?php

namespace App\Http\Resources\Api\V1\Admin\Bookings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminBookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_number' => $this->booking_number,
            'status' => $this->status->value,
            'requested_date' => $this->requested_date?->toDateString(),
            'requested_time_window' => $this->requested_time_window,
            'scheduled_start_at' => $this->scheduled_start_at?->toISOString(),
            'scheduled_end_at' => $this->scheduled_end_at?->toISOString(),
            'customer_notes' => $this->customer_notes,
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toISOString(),
            'customer' => $this->whenLoaded('customerProfile', fn (): array => [
                'id' => $this->customerProfile->id,
                'customer_number' => $this->customerProfile->customer_number,
                'full_name' => $this->customerProfile->full_name,
            ]),
            'service' => $this->whenLoaded('service', fn (): array => [
                'id' => $this->service->id,
                'name' => $this->service->name,
            ]),
            'service_mode' => $this->whenLoaded('serviceMode', fn (): ?array => $this->serviceMode === null ? null : [
                'id' => $this->serviceMode->id,
                'mode' => $this->serviceMode->mode?->value,
                'subtype' => $this->serviceMode->subtype?->value,
            ]),
            'quotations' => $this->whenLoaded('quotations', fn () => $this->quotations->map(
                fn ($quotation): array => [
                    'id' => $quotation->id,
                    'quotation_number' => $quotation->quotation_number,
                    'status' => $quotation->status->value,
                    'total_amount' => $quotation->total_amount,
                    'payment_type' => $quotation->payment_type?->value,
                    'deposit_percentage' => $quotation->deposit_percentage,
                    'deposit_amount' => $quotation->deposit_amount,
                    'remaining_amount' => $quotation->remaining_amount,
                    'accepted_at' => $quotation->accepted_at?->toISOString(),
                ],
            )->all()),
            'status_histories' => $this->whenLoaded('statusHistories', fn () => $this->statusHistories->map(
                fn ($history): array => [
                    'status' => $history->status->value,
                    'changed_by_type' => $history->changed_by_type,
                    'changed_by_id' => $history->changed_by_id,
                    'notes' => $history->notes,
                    'created_at' => $history->created_at?->toISOString(),
                ],
            )->all()),
        ];
    }
}
