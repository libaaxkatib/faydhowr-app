<?php

namespace App\Http\Resources\Api\V1\Booking;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'booking_number' => $this->booking_number,
            'service' => $this->whenLoaded('service', fn (): array => [
                'name' => $this->service->name,
                'slug' => $this->service->slug,
                'starting_from_price' => $this->service->starting_from_price,
                'currency' => $this->service->currency,
                'duration_minutes' => $this->service->duration_minutes,
            ]),
            'service_mode' => $this->whenLoaded('serviceMode', fn (): array => [
                'mode' => $this->serviceMode->mode->value,
                'subtype' => $this->serviceMode->subtype?->value,
            ]),
            'status' => $this->status->value,
            'requested_date' => $this->requested_date?->toDateString(),
            'requested_time_window' => $this->requested_time_window,
            'scheduled_start_at' => $this->scheduled_start_at?->toISOString(),
            'scheduled_end_at' => $this->scheduled_end_at?->toISOString(),
            'address_snapshot' => $this->address_snapshot,
            'customer_notes' => $this->customer_notes,
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
