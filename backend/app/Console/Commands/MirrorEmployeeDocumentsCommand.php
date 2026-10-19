<?php

namespace App\Console\Commands;

use App\Support\Hr\Documents\DocumentMirror;
use Illuminate\Console\Command;

/**
 * Copies employee documents and client-company agreements from their disks to the recovery-mirror disk and verifies every copy
 * by SHA-256.
 *
 * Copy-only: nothing is ever deleted from, or overwritten on, either disk, and the database is not touched. Safe to run
 * repeatedly (what is already mirrored is recognised and skipped). Exits with a failure code if anything could not be
 * mirrored or verified, so a scheduler or cron service reports it. Output is counts and internal ids only.
 *
 * A routine run only checks existence and size of what is already mirrored (cheap); --deep re-hashes everything.
 *
 * Intended to run from a cron service (for example hourly) that has both disks configured - it is not scheduled by this
 * application, and no scheduler or worker is created by shipping it.
 */
class MirrorEmployeeDocumentsCommand extends Command
{
    protected $signature = 'hr:documents-mirror
        {--primary-disk= : Disk that holds the documents (default: filesystems.hr_documents_disk)}
        {--agreements-disk= : Disk that holds client-company agreements (default: filesystems.hr_attachments_disk)}
        {--mirror-disk= : Disk that receives the recovery copy (default: filesystems.hr_documents_mirror_disk)}
        {--attempts=3 : Tries per document before it is reported as failed}
        {--dry-run : Report what would be copied without writing anything}
        {--deep : Re-read and re-hash every document on both disks (slow; use for a periodic full verification)}
        {--details : List each failed document by internal id}
        {--json : Print the report as JSON}';

    protected $description = 'Copy employee documents to the recovery mirror and verify each copy (copy-only; never deletes or overwrites).';

    public function handle(): int
    {
        $primary = (string) ($this->option('primary-disk') ?: config('filesystems.hr_documents_disk', 'local'));
        $mirror = (string) ($this->option('mirror-disk') ?: config('filesystems.hr_documents_mirror_disk'));
        $agreements = (string) ($this->option('agreements-disk') ?: config('filesystems.hr_attachments_disk', $primary));

        if ($mirror === '') {
            $this->error('No recovery-mirror disk is configured (set HR_DOCUMENTS_MIRROR_DISK or pass --mirror-disk). Nothing was copied.');

            return self::FAILURE;
        }

        foreach ([$primary, $agreements, $mirror] as $disk) {
            if (! is_array(config("filesystems.disks.{$disk}"))) {
                $this->error("Unknown storage disk '{$disk}'. Nothing was copied.");

                return self::FAILURE;
            }
        }

        $report = (new DocumentMirror($primary, $mirror, max(1, (int) $this->option('attempts')), agreementsDisk: $agreements))->mirror((bool) $this->option('dry-run'), (bool) $this->option('deep'));

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info($report['dry_run'] ? 'Document and agreement mirror - dry run (nothing was written).' : 'Document and agreement mirror run finished.');

            if ($report['aborted'] !== null) {
                $this->error('The run could not start: '.str_replace('_', ' ', (string) $report['aborted']).'. Nothing was reported as missing and nothing was copied.');
            }

            $this->table(['Measure', 'Count'], collect($report['counts'])->map(fn ($n, $k) => [str_replace('_', ' ', $k), (string) $n])->values()->all());

            if ($this->option('details') && $report['failures'] !== []) {
                $this->table(['File', 'Owner', 'Problem'], collect($report['failures'])->map(fn ($f) => isset($f['agreement_id'])
                    ? ['agreement '.$f['agreement_id'], 'company '.$f['client_company_id'], $f['reason']]
                    : ['document '.$f['document_id'], 'employee '.$f['employee_id'], $f['reason']])->all());
            }

            $this->line($report['ok'] ? 'Every document was copied or already present, and verified.' : 'Some documents were NOT mirrored - see the counts above (use --details for ids).');
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
