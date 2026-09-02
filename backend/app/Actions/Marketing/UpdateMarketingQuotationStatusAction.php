<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Enums\Marketing\MarketingQuotationStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\MarketingQuotation;

class UpdateMarketingQuotationStatusAction
{
    public function handle(MarketingQuotation $quotation, MarketingQuotationStatus $status, Admin $actor): MarketingQuotation
    {
        $quotation->update(['status' => $status]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Marketing quotation #{$quotation->id} status changed to {$status->value}.",
            entityType: MarketingQuotation::class,
            entityId: $quotation->id,
        ));

        return $quotation->load('createdBy');
    }
}
