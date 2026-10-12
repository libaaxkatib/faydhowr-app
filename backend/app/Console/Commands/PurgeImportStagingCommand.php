<?php

namespace App\Console\Commands;

use App\Models\ImportBatch;
use App\Support\Hr\Import\ImportRetention;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Deletes personal data from HR import staging 30 days after the upload (UTC), and from cancelled
 * imports immediately. Keeps the non-sensitive audit trail. Safe to run repeatedly.
 */
class PurgeImportStagingCommand extends Command
{
    protected $signature = 'hr:purge-import-staging
        {--limit=200 : Maximum number of batches to purge in this run}
        {--dry-run : Only list what would be purged}';

    protected $description = 'Purge personal data from expired HR import staging records (30-day retention, UTC).';

    public function handle(ImportRetention $retention): int
    {
        if ($this->option('dry-run')) {
            $due = ImportBatch::query()
                ->whereNull('pii_purged_at')
                ->where(fn ($q) => $q->where('retention_expires_at', '<=', CarbonImmutable::now('UTC'))->orWhere('status', 'cancelled'))
                ->count();

            $this->info("{$due} import batch(es) are due for purging (nothing was changed).");

            return self::SUCCESS;
        }

        $result = $retention->purgeExpired(max(1, (int) $this->option('limit')));

        $this->info("Purged personal data from {$result['purged']} import batch(es).");

        if ($result['failed'] !== []) {
            $this->error('Could not purge batch id(s): '.implode(', ', $result['failed']).'. They will be retried on the next run.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
