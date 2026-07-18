<?php

namespace App\Http\Resources\Api\V1\Admin\Quotations;

use App\Http\Resources\Api\V1\Quotation\QuotationAttachmentResource;
use App\Http\Resources\Api\V1\Quotation\QuotationDiscussionMessageResource;
use App\Http\Resources\Api\V1\Quotation\QuotationRevisionResource;
use App\Http\Resources\Api\V1\Quotation\QuotationTimelineEventResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin quotation payload (API Design §18.10). Carries the §9.4A standard
 * core (`quotation_number`, `latest_version`, `status`, `can_accept`,
 * `can_discuss`) plus reviewer, customer, revision chain, discussion, and
 * timeline for the detail view.
 */
class AdminQuotationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quotation_number' => $this->quotation_number,
            'latest_version' => $this->latestRevision?->version_number,
            'status' => $this->status->value,
            'can_accept' => $this->canAccept(),
            'can_discuss' => $this->canDiscuss(),
            'customer' => $this->whenLoaded('customerProfile', fn (): ?array => $this->customerProfile === null ? null : [
                'id' => $this->customerProfile->id,
                'full_name' => $this->customerProfile->full_name,
            ]),
            'booking' => $this->whenLoaded('booking', fn (): ?array => $this->booking === null ? null : [
                'id' => $this->booking->id,
                'booking_number' => $this->booking->booking_number,
                'status' => $this->booking->status->value,
            ]),
            'assigned_admin' => $this->whenLoaded('assignedAdmin', fn (): ?array => $this->assignedAdmin === null ? null : [
                'id' => $this->assignedAdmin->id,
                'full_name' => $this->assignedAdmin->full_name,
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
            'revisions' => $this->whenLoaded(
                'revisions',
                fn (): mixed => QuotationRevisionResource::collection(
                    $this->revisions->each(fn ($revision) => $revision->setRelation('quotation', $this->resource)),
                ),
            ),
            'attachments' => $this->whenLoaded(
                'attachments',
                fn (): mixed => QuotationAttachmentResource::collection($this->attachments),
            ),
            'discussion' => $this->whenLoaded(
                'discussionMessages',
                fn (): mixed => QuotationDiscussionMessageResource::collection($this->discussionMessages),
            ),
            'timeline' => $this->whenLoaded(
                'statusHistories',
                fn (): mixed => QuotationTimelineEventResource::collection($this->statusHistories),
            ),
            'payment_type' => $this->payment_type?->value,
            'deposit_percentage' => $this->deposit_percentage,
            'deposit_amount' => $this->deposit_amount,
            'remaining_amount' => $this->remaining_amount,
            'valid_until' => $this->valid_until?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'accepted_at' => $this->accepted_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
