<?php

namespace App\Support\Hr\Import;

use App\Enums\AuditAction;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Confirm and process a staged batch.
 *
 * - confirm() is idempotent: confirming twice, or two admins confirming at once, starts one run.
 * - processNext() imports at most BATCH_SIZE rows per call, so no request is long-running and no queue
 *   worker is needed; the panel simply calls it until the batch is complete.
 * - A short lease (claimed with one atomic UPDATE) means two overlapping calls can never process the same
 *   rows; a second caller gets IMPORT_BUSY and retries.
 * - Each row runs in its own transaction and re-checks the live data, so one bad row never rolls back or
 *   blocks the others (partial success), and a crash leaves the row untouched and still pending.
 * - A row that already exists is skipped, never updated.
 */
final class ImportProcessor
{
    public const BATCH_SIZE = 100;

    public const LEASE_SECONDS = 120;

    public function __construct(private readonly ImportRegistry $registry) {}

    public function confirm(ImportBatch $batch, Admin $actor): ImportBatch
    {
        return DB::transaction(function () use ($batch, $actor): ImportBatch {
            $locked = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->type->isPreviewOnly()) {
                throw new ImportDomainException('PREVIEW_ONLY', 'This is a read-only preview. It cannot be confirmed and never changes any employee.', 422);
            }

            if ($locked->status === ImportBatchStatus::Cancelled) {
                throw new ImportDomainException('IMPORT_CANCELLED', 'This import was cancelled.', 409);
            }

            if ($locked->isPurged()) {
                throw new ImportDomainException('IMPORT_EXPIRED', 'The staging data for this import has been deleted. Upload the file again.', 409);
            }

            if ($locked->status !== ImportBatchStatus::Staged) {
                return $locked; // already confirmed / completed: nothing more to do
            }

            $locked->update([
                'status' => ImportBatchStatus::Processing,
                'confirmed_by' => $actor->id,
                'confirmed_at' => CarbonImmutable::now('UTC'),
            ]);

            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: "HR import #{$locked->id} ({$locked->type->value}) confirmed.",
                entityType: ImportBatch::class,
                entityId: $locked->id,
                metadata: ['import_batch_id' => $locked->id, 'type' => $locked->type->value, 'rows_to_import' => $locked->valid_rows],
            ));

            if ($locked->valid_rows === 0) {
                $this->complete($locked);
            }

            return $locked->refresh();
        });
    }

    /**
     * Imports the next bounded set of valid rows.
     *
     * @throws ImportDomainException
     */
    public function processNext(ImportBatch $batch, Admin $actor, int $limit = self::BATCH_SIZE): ImportBatch
    {
        $limit = max(1, min($limit, self::BATCH_SIZE));
        $batch = $batch->fresh();

        if ($batch->status === ImportBatchStatus::Completed) {
            return $batch;
        }

        if ($batch->status !== ImportBatchStatus::Processing) {
            throw new ImportDomainException('IMPORT_NOT_CONFIRMED', 'Confirm the import before processing it.', 409);
        }

        $token = Str::random(32);
        $now = CarbonImmutable::now('UTC');

        $claimed = ImportBatch::query()
            ->whereKey($batch->id)
            ->where('status', ImportBatchStatus::Processing->value)
            ->where(fn ($q) => $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', $now))
            ->update(['lease_token' => $token, 'lease_expires_at' => $now->addSeconds(self::LEASE_SECONDS)]);

        if ($claimed === 0) {
            throw new ImportDomainException('IMPORT_BUSY', 'This import is already being processed. Wait a moment and try again.', 409);
        }

        try {
            $handler = $this->registry->for($batch->type);

            $rows = ImportRow::query()
                ->where('import_batch_id', $batch->id)
                ->where('status', ImportRowStatus::Valid->value)
                ->orderBy('row_number')
                ->limit($limit)
                ->get();

            foreach ($rows as $row) {
                $this->processRow($handler, $row, $batch, $actor);
            }

            $this->refreshCounts($batch);

            $remaining = ImportRow::query()->where('import_batch_id', $batch->id)->where('status', ImportRowStatus::Valid->value)->exists();

            if (! $remaining) {
                $this->complete($batch->fresh());
            }
        } finally {
            ImportBatch::query()->whereKey($batch->id)->where('lease_token', $token)->update(['lease_token' => null, 'lease_expires_at' => null]);
        }

        return $batch->fresh();
    }

    private function processRow(ImportTypeHandler $handler, ImportRow $row, ImportBatch $batch, Admin $actor): void
    {
        try {
            DB::transaction(function () use ($handler, $row, $batch, $actor): void {
                $locked = ImportRow::query()->whereKey($row->id)->lockForUpdate()->first();

                if ($locked === null || $locked->status !== ImportRowStatus::Valid) {
                    return; // another run already handled it
                }

                $outcome = $handler->process($locked, $batch, $actor);

                $locked->update([
                    'status' => $outcome->status,
                    'code' => $outcome->code,
                    'message' => mb_substr($outcome->message, 0, 500),
                    'entity_type' => $outcome->entityType ?? $locked->entity_type,
                    'entity_id' => $outcome->entityId ?? $locked->entity_id,
                    'processed_at' => CarbonImmutable::now('UTC'),
                ]);
            });
        } catch (HttpResponseException $e) {
            $payload = $e->getResponse()->getData(true);
            $this->markFailed($row, (string) ($payload['error_code'] ?? 'ROW_REFUSED'), (string) ($payload['message'] ?? 'The row was refused.'));
        } catch (Throwable $e) {
            report($e);
            $this->markFailed($row, 'UNEXPECTED_ERROR', 'The row could not be imported because of an unexpected error. It was rolled back.');
        }
    }

    private function markFailed(ImportRow $row, string $code, string $message): void
    {
        ImportRow::query()->whereKey($row->id)->where('status', ImportRowStatus::Valid->value)->update([
            'status' => ImportRowStatus::Failed->value,
            'code' => $code,
            'message' => mb_substr($message, 0, 500),
            'processed_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function refreshCounts(ImportBatch $batch): void
    {
        $counts = ImportRow::query()
            ->where('import_batch_id', $batch->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        ImportBatch::query()->whereKey($batch->id)->update([
            'imported_rows' => (int) ($counts[ImportRowStatus::Imported->value] ?? 0),
            'skipped_rows' => (int) ($counts[ImportRowStatus::Skipped->value] ?? 0),
            'failed_rows' => (int) ($counts[ImportRowStatus::Failed->value] ?? 0),
        ]);
    }

    private function complete(ImportBatch $batch): void
    {
        $this->refreshCounts($batch);

        $batch->update(['status' => ImportBatchStatus::Completed, 'completed_at' => CarbonImmutable::now('UTC')]);
        $batch->refresh();

        $actor = $batch->confirmed_by ? Admin::query()->find($batch->confirmed_by) : null;

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "HR import #{$batch->id} ({$batch->type->value}) completed.",
            entityType: ImportBatch::class,
            entityId: $batch->id,
            metadata: [
                'import_batch_id' => $batch->id,
                'type' => $batch->type->value,
                'total' => $batch->total_rows,
                'imported' => $batch->imported_rows,
                'skipped' => $batch->skipped_rows,
                'failed' => $batch->failed_rows,
                'not_imported_invalid' => $batch->invalid_rows,
                'not_imported_duplicate' => $batch->duplicate_rows,
                'not_imported_review' => $batch->review_rows,
            ],
        ));
    }

    /** Discards a staged batch before confirmation. Its staging data is purged immediately. */
    public function cancel(ImportBatch $batch, Admin $actor, ImportRetention $retention): ImportBatch
    {
        $cancelled = DB::transaction(function () use ($batch): ImportBatch {
            $locked = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ImportBatchStatus::Cancelled) {
                return $locked;
            }

            if ($locked->status !== ImportBatchStatus::Staged) {
                throw new ImportDomainException('IMPORT_NOT_CANCELLABLE', 'Only an import that has not been confirmed can be cancelled.', 409);
            }

            $locked->update(['status' => ImportBatchStatus::Cancelled]);

            return $locked;
        });

        $retention->purgeBatch($cancelled);

        event(AuditEvent::record(
            action: AuditAction::Cancel,
            admin: $actor,
            description: "HR import #{$cancelled->id} ({$cancelled->type->value}) cancelled.",
            entityType: ImportBatch::class,
            entityId: $cancelled->id,
        ));

        return $cancelled->refresh();
    }
}
