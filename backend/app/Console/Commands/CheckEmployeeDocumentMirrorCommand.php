<?php

namespace App\Console\Commands;

use App\Support\Hr\Documents\DocumentMirror;
use Illuminate\Console\Command;

/**
 * Freshness check for the recovery mirror: fails if the last run is missing, too old, or recorded failures.
 * Read-only. Designed for a separate cron service so a mirror that silently stopped is noticed.
 */
class CheckEmployeeDocumentMirrorCommand extends Command
{
    protected $signature = 'hr:documents-mirror-check
        {--mirror-disk= : Mirror disk (default: filesystems.hr_documents_mirror_disk)}
        {--max-age-hours=26 : Fail when the last mirror run finished longer ago than this}
        {--json : Print the result as JSON}';

    protected $description = 'Read-only: fail when the employee-document mirror is stale or its last run had failures.';

    public function handle(): int
    {
        $mirror = (string) ($this->option('mirror-disk') ?: config('filesystems.hr_documents_mirror_disk'));

        if ($mirror === '' || ! is_array(config("filesystems.disks.{$mirror}"))) {
            $this->error('No recovery-mirror disk is configured, so the mirror cannot be checked.');

            return self::FAILURE;
        }

        $result = (new DocumentMirror((string) config('filesystems.hr_documents_disk', 'local'), $mirror))->freshness(max(1, (int) $this->option('max-age-hours')));

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT));
        } elseif ($result['ok']) {
            $this->info("Mirror is fresh: last run finished {$result['finished_at']} ({$result['age_hours']} h ago) with no failures.");
        } else {
            $this->error('Mirror check FAILED: '.str_replace('_', ' ', (string) $result['reason']).($result['finished_at'] ? " (last run {$result['finished_at']}, failures: {$result['failed_total']})" : '').'.');
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
