<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Enums\Marketing\MarketingQuotationStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\MarketingQuotation;
use App\Models\MarketingRecord;

class CreateMarketingQuotationAction
{
    public function handle(MarketingRecord $record, array $data, Admin $actor): MarketingQuotation
    {
        $quotation = MarketingQuotation::query()->create([
            ...$data,
            'marketing_record_id' => $record->id,
            'status' => MarketingQuotationStatus::Draft,
            'created_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Quotation added for marketing record '{$record->record_number}'.",
            entityType: MarketingQuotation::class,
            entityId: $quotation->id,
            metadata: ['marketing_record_id' => $record->id],
        ));

        return $quotation->load('createdBy');
    }
}
