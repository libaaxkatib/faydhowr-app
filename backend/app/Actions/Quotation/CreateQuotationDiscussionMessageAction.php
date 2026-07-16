<?php

namespace App\Actions\Quotation;

use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\QuotationDiscussionMessage;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class CreateQuotationDiscussionMessageAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        CustomerProfile $profile,
        int $quotationId,
        array $attributes,
    ): QuotationDiscussionMessage {
        return DB::transaction(function () use ($profile, $quotationId, $attributes): QuotationDiscussionMessage {
            $profile = CustomerProfile::query()
                ->whereKey($profile)
                ->lockForUpdate()
                ->firstOrFail();

            $quotation = $profile
                ->quotations()
                ->whereKey($quotationId)
                ->lockForUpdate()
                ->first();

            if ($quotation === null) {
                throw new ModelNotFoundException;
            }

            if (! in_array($quotation->status, [
                QuotationStatus::QuotationReady,
                QuotationStatus::UnderDiscussion,
            ], true)) {
                throw new DomainException('Discussion is not available for this quotation.');
            }

            $message = $quotation->discussionMessages()->create([
                'sender_type' => 'user',
                'sender_id' => $profile->user_id,
                'message' => $attributes['message'],
                'attachments' => $attributes['attachments'] ?? null,
            ]);

            if ($quotation->status === QuotationStatus::QuotationReady) {
                $quotation->status = QuotationStatus::UnderDiscussion;
                $quotation->save();

                $quotation->statusHistories()->create([
                    'status' => QuotationStatus::UnderDiscussion,
                    'changed_by_type' => 'user',
                    'changed_by_id' => $profile->user_id,
                    'notes' => null,
                ]);
            }

            return $message;
        });
    }
}
