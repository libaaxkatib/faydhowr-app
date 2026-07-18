<?php

namespace App\Actions\Quotation;

use App\Enums\AuditAction;
use App\Enums\QuotationStatus;
use App\Events\Audit\AuditEvent;
use App\Events\Quotation\QuotationDiscussionReplyCreated;
use App\Exceptions\Quotation\QuotationInvalidStateException;
use App\Models\Admin;
use App\Models\Quotation;
use App\Models\QuotationDiscussionMessage;
use Illuminate\Support\Facades\DB;

/**
 * Team reply on the quotation discussion thread (Sprint 28). Replies never
 * change the quotation status and are append-only.
 */
class CreateAdminQuotationDiscussionMessageAction
{
    public function handle(Admin $admin, int $quotationId, string $message): QuotationDiscussionMessage
    {
        [$quotation, $discussionMessage] = DB::transaction(function () use ($admin, $quotationId, $message): array {
            $quotation = Quotation::query()
                ->whereKey($quotationId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($quotation->status, [
                QuotationStatus::QuotationReady,
                QuotationStatus::UnderDiscussion,
            ], true)) {
                throw QuotationInvalidStateException::forAction('Discussion is not available for this quotation.');
            }

            $discussionMessage = $quotation->discussionMessages()->create([
                'sender_type' => 'admin',
                'sender_id' => $admin->id,
                'message' => $message,
            ]);

            DB::afterCommit(fn (): mixed => QuotationDiscussionReplyCreated::dispatch($quotation, $discussionMessage));

            return [$quotation, $discussionMessage->load('attachments.upload')];
        });

        event(AuditEvent::record(
            action: AuditAction::QuotationDiscussionReply,
            admin: $admin,
            description: 'Quotation discussion reply posted.',
            entityType: Quotation::class,
            entityId: $quotation->id,
            metadata: [
                'quotation_number' => $quotation->quotation_number,
                'previous_status' => $quotation->status->value,
                'new_status' => $quotation->status->value,
                'message_id' => $discussionMessage->id,
            ],
        ));

        return $discussionMessage;
    }
}
