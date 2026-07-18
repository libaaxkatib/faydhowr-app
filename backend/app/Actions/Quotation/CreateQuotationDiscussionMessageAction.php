<?php

namespace App\Actions\Quotation;

use App\Enums\QuotationStatus;
use App\Events\Quotation\QuotationDiscussionReplyCreated;
use App\Exceptions\Quotation\QuotationInvalidStateException;
use App\Models\CustomerProfile;
use App\Models\QuotationDiscussionMessage;
use App\Models\Upload;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Customer discussion message (Sprint 28). Attachments reference staged
 * uploads by UUID — never JSON blobs — and this is the only channel for
 * additional customer files after Submit. A message from `quotation_ready`
 * transitions the quotation to `under_discussion`.
 */
class CreateQuotationDiscussionMessageAction
{
    public function __construct(private ResolveQuotationUploadsAction $resolveUploads) {}

    /**
     * @param  list<string>  $uploadUuids
     */
    public function handle(
        CustomerProfile $profile,
        int $quotationId,
        string $message,
        array $uploadUuids = [],
    ): QuotationDiscussionMessage {
        return DB::transaction(function () use ($profile, $quotationId, $message, $uploadUuids): QuotationDiscussionMessage {
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
                throw QuotationInvalidStateException::forAction('Discussion is not available for this quotation.');
            }

            $discussionMessage = $quotation->discussionMessages()->create([
                'sender_type' => 'user',
                'sender_id' => $profile->user_id,
                'message' => $message,
            ]);

            $this->resolveUploads
                ->handle($profile, $uploadUuids)
                ->each(function (Upload $upload) use ($discussionMessage): void {
                    $discussionMessage->attachments()->create(['upload_id' => $upload->id]);
                    $upload->update(['attached_at' => now()]);
                });

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

            DB::afterCommit(fn (): mixed => QuotationDiscussionReplyCreated::dispatch($quotation, $discussionMessage));

            return $discussionMessage->load('attachments.upload');
        });
    }
}
