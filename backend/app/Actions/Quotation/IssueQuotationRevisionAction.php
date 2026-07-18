<?php

namespace App\Actions\Quotation;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\DataTransferObjects\Quotation\QuotationRevisionData;
use App\Enums\AuditAction;
use App\Enums\QuotationRevisionSource;
use App\Enums\QuotationStatus;
use App\Events\Audit\AuditEvent;
use App\Events\Quotation\QuotationIssued;
use App\Events\Quotation\QuotationRevised;
use App\Exceptions\Quotation\QuotationInvalidStateException;
use App\Models\Admin;
use App\Models\Quotation;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Creates an immutable pricing revision under the quotation row lock
 * (Sprint 28). Version numbers are strictly increasing, never reused, and
 * never reset; `latest_revision_id` advances to the new (highest) version in
 * the same transaction. The quotation head mirrors the latest revision's
 * totals so the Sprint 26 payment snapshot keeps working unchanged.
 *
 * `$initial = true` issues Version 1 (`under_review` → `quotation_ready`);
 * otherwise a follow-up revision from `quotation_ready` / `under_discussion`
 * or an `expired` quotation (automatic revival) returns it to `quotation_ready`.
 */
class IssueQuotationRevisionAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    public function handle(Admin $admin, int $quotationId, QuotationRevisionData $data, bool $initial): Quotation
    {
        $this->assertConsistentTotals($data);

        [$quotation, $revision, $previousStatus] = DB::transaction(function () use ($admin, $quotationId, $data, $initial): array {
            $quotation = Quotation::query()
                ->whereKey($quotationId)
                ->lockForUpdate()
                ->firstOrFail();

            $previousStatus = $quotation->status;

            $allowedStatuses = $initial
                ? [QuotationStatus::UnderReview]
                : [QuotationStatus::QuotationReady, QuotationStatus::UnderDiscussion, QuotationStatus::Expired];

            if (! in_array($quotation->status, $allowedStatuses, true)) {
                throw QuotationInvalidStateException::forAction($initial
                    ? 'A quotation can be issued only while under review.'
                    : 'A revision cannot be issued in the current quotation status.');
            }

            $nextVersionNumber = ((int) $quotation->revisions()->max('version_number')) + 1;

            $revision = $quotation->revisions()->create([
                'version_number' => $nextVersionNumber,
                'source' => QuotationRevisionSource::AdminIssue,
                'subtotal_amount' => $data->subtotal,
                'discount_amount' => $data->discountAmount,
                'tax_amount' => $data->taxAmount,
                'total_amount' => $data->totalAmount,
                'valid_until' => $data->validUntil,
                'terms' => $data->terms,
                'notes' => $data->notes,
                'issued_by_admin_id' => $admin->id,
            ]);

            $quotation->update([
                'latest_revision_id' => $revision->id,
                'status' => QuotationStatus::QuotationReady,
                'subtotal' => $data->subtotal,
                'discount_amount' => $data->discountAmount,
                'tax_amount' => $data->taxAmount,
                'total_amount' => $data->totalAmount,
                'valid_until' => $data->validUntil,
            ]);

            if ($previousStatus !== QuotationStatus::QuotationReady) {
                $quotation->statusHistories()->create([
                    'status' => QuotationStatus::QuotationReady,
                    'changed_by_type' => 'admin',
                    'changed_by_id' => $admin->id,
                    'notes' => "Version {$nextVersionNumber} issued.",
                ]);
            }

            DB::afterCommit(fn (): mixed => $initial
                ? QuotationIssued::dispatch($quotation, $revision)
                : QuotationRevised::dispatch($quotation, $revision));

            return [$quotation->load(['latestRevision.issuedByAdmin', 'booking']), $revision, $previousStatus];
        });

        event(AuditEvent::record(
            action: $initial ? AuditAction::QuotationIssue : AuditAction::QuotationRevision,
            admin: $admin,
            description: $initial ? 'Quotation issued.' : 'Quotation revision issued.',
            entityType: Quotation::class,
            entityId: $quotation->id,
            metadata: [
                'quotation_number' => $quotation->quotation_number,
                'version_number' => $revision->version_number,
                'previous_status' => $previousStatus->value,
                'new_status' => $quotation->status->value,
                'total_amount' => (string) $revision->total_amount,
                'valid_until' => $revision->valid_until->toISOString(),
            ],
        ));

        $this->dashboardCache->invalidate();

        return $quotation;
    }

    /**
     * Pricing consistency (API Design §18.10): total = subtotal − discount + tax
     * and discount ≤ subtotal, compared in cents to avoid float drift.
     */
    private function assertConsistentTotals(QuotationRevisionData $data): void
    {
        $subtotal = $this->toCents($data->subtotal);
        $discountAmount = $this->toCents($data->discountAmount);
        $taxAmount = $this->toCents($data->taxAmount);
        $totalAmount = $this->toCents($data->totalAmount);

        if ($discountAmount > $subtotal || $totalAmount !== $subtotal - $discountAmount + $taxAmount) {
            throw new DomainException('The total amount must equal subtotal minus discount plus tax.');
        }
    }

    private function toCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
