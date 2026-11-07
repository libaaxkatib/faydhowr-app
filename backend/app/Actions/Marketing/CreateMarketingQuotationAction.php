<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Enums\Marketing\MarketingQuotationStatus;
use App\Enums\Marketing\MarketingRecordStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\MarketingQuotation;
use App\Models\MarketingRecord;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision Q-STATUS (2026-11-06): creating a quotation moves its record to QUOTATION status. Only a PENDING record moves (through the ordinary status
 * action, so the change is audited with its from / to status); a record that is already Quotation stays as it is, and a Done or Cancelled record is never
 * moved back by a new quotation. The quotation itself is created exactly as before (one row, Draft), so nothing is duplicated.
 */
class CreateMarketingQuotationAction
{
    public function __construct(private readonly UpdateMarketingRecordStatusAction $status = new UpdateMarketingRecordStatusAction) {}

    public function handle(MarketingRecord $record, array $data, Admin $actor): MarketingQuotation
    {
        $quotation = DB::transaction(function () use ($record, $data, $actor): MarketingQuotation {
            $quotation = MarketingQuotation::query()->create([
                ...$data,
                'marketing_record_id' => $record->id,
                'status' => MarketingQuotationStatus::Draft,
                'created_by' => $actor->id,
            ]);

            if ($record->status === MarketingRecordStatus::Pending) {
                $this->status->handle($record, MarketingRecordStatus::Quotation, $actor);
            }

            return $quotation;
        });

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
