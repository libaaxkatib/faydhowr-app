<?php

namespace App\Support\Hr\Import;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Retention of import staging data.
 *
 * Cutoff: a batch's personal data expires RETENTION_DAYS (30) after the upload, measured in UTC
 * (retention_expires_at is stored in UTC and compared with the current UTC time).
 *
 * What is removed: each row's payload, name_key, phone_key and meta (the only places personal data
 * lives). What is kept forever: the batch (who, when, type, file checksum and size, totals, outcome
 * counts) and per-row row number, status, code, message and the id of any record created or matched.
 * Messages never contain names or phone numbers. The uploaded workbook itself is never stored.
 *
 * Purging is idempotent (a purged batch is skipped), each batch is purged in its own transaction so one
 * failure never blocks the rest, and a batch that was never finished is closed first so nothing is left
 * waiting on data that no longer exists.
 */
final class ImportRetention
{
    /**
     * @return array{purged: int, failed: list<int>}
     */
    public function purgeExpired(int $limit = 100): array
    {
        $now = CarbonImmutable::now('UTC');

        $batches = ImportBatch::query()
            ->whereNull('pii_purged_at')
            ->where(fn ($q) => $q
                ->where('retention_expires_at', '<=', $now)
                ->orWhere('status', ImportBatchStatus::Cancelled->value))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $purged = 0;
        $failed = [];

        foreach ($batches as $batch) {
            try {
                if ($this->purgeBatch($batch)) {
                    $purged++;
                }
            } catch (Throwable $e) {
                report($e);
                $failed[] = $batch->id;
            }
        }

        return ['purged' => $purged, 'failed' => $failed];
    }

    /**
     * @return bool true when this call purged the batch, false when it was already purged
     */
    public function purgeBatch(ImportBatch $batch): bool
    {
        return DB::transaction(function () use ($batch): bool {
            $locked = ImportBatch::query()->whereKey($batch->id)->lockForUpdate()->first();

            if ($locked === null || $locked->pii_purged_at !== null) {
                return false;
            }

            // Rows that were never imported can no longer be imported once their data is gone.
            $pending = ImportRow::query()->where('import_batch_id', $locked->id)->where('status', ImportRowStatus::Valid->value);

            if ($pending->exists()) {
                $pending->update([
                    'status' => ImportRowStatus::Skipped->value,
                    'code' => 'RETENTION_EXPIRED',
                    'message' => 'Not imported before the staging data was deleted.',
                    'processed_at' => CarbonImmutable::now('UTC'),
                ]);
            }

            ImportRow::query()->where('import_batch_id', $locked->id)->update([
                'payload' => null,
                'name_key' => null,
                'phone_key' => null,
                'meta' => null,
            ]);

            $status = match ($locked->status) {
                ImportBatchStatus::Staged => ImportBatchStatus::Cancelled,
                ImportBatchStatus::Processing => ImportBatchStatus::Completed,
                default => $locked->status,
            };

            $locked->update([
                'status' => $status,
                'completed_at' => $locked->completed_at ?? ($status === ImportBatchStatus::Completed ? CarbonImmutable::now('UTC') : null),
                'lease_token' => null,
                'lease_expires_at' => null,
                'pii_purged_at' => CarbonImmutable::now('UTC'),
                'skipped_rows' => ImportRow::query()->where('import_batch_id', $locked->id)->where('status', ImportRowStatus::Skipped->value)->count(),
            ]);

            return true;
        });
    }
}
