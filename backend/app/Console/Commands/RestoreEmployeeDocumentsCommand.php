<?php

namespace App\Console\Commands;

use App\Support\Hr\Documents\DocumentMirror;
use Illuminate\Console\Command;

/**
 * Restores employee documents and client-company agreements from the recovery mirror into their disks - only where the primary object is confirmed
 * missing, only from a mirror object whose SHA-256 equals the checksum recorded for the document, and only with --execute.
 *
 * Without --execute it reports what could be restored and changes nothing. It never overwrites an existing object, never
 * deletes anything from the mirror, never changes the database, and never reports a document as restored until the copy has
 * been read back and verified. A document that is not in the mirror (or cannot be verified) is listed as needing a new
 * upload from the employee - it is never presented as recovered.
 */
class RestoreEmployeeDocumentsCommand extends Command
{
    protected $signature = 'hr:documents-restore
        {--execute : Actually copy verified files back (default is a report only)}
        {--document=* : Only these employee-document ids}
        {--agreement=* : Only these client-company agreement ids (restricting documents alone leaves agreements out)}
        {--agreements-disk= : Disk that holds client-company agreements (default: filesystems.hr_attachments_disk)}
        {--primary-disk= : Disk to restore into (default: filesystems.hr_documents_disk)}
        {--mirror-disk= : Disk to restore from (default: filesystems.hr_documents_mirror_disk)}
        {--details : List each document that needs attention by internal id}
        {--json : Print the report as JSON}';

    protected $description = 'Restore missing employee documents from the verified recovery mirror (report only unless --execute).';

    public function handle(): int
    {
        $primary = (string) ($this->option('primary-disk') ?: config('filesystems.hr_documents_disk', 'local'));
        $mirror = (string) ($this->option('mirror-disk') ?: config('filesystems.hr_documents_mirror_disk'));
        $agreements = (string) ($this->option('agreements-disk') ?: config('filesystems.hr_attachments_disk', $primary));

        if ($mirror === '') {
            $this->error('No recovery-mirror disk is configured (set HR_DOCUMENTS_MIRROR_DISK or pass --mirror-disk). Nothing was restored.');

            return self::FAILURE;
        }

        foreach ([$primary, $agreements, $mirror] as $disk) {
            if (! is_array(config("filesystems.disks.{$disk}"))) {
                $this->error("Unknown storage disk '{$disk}'. Nothing was restored.");

                return self::FAILURE;
            }
        }

        $ids = array_values(array_filter(array_map('intval', (array) $this->option('document'))));
        $agreementIds = array_values(array_filter(array_map('intval', (array) $this->option('agreement'))));
        $report = (new DocumentMirror($primary, $mirror, agreementsDisk: $agreements))->restore((bool) $this->option('execute'), $ids === [] ? null : $ids, $agreementIds === [] ? null : $agreementIds);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info($report['executed'] ? 'Document and agreement restore finished.' : 'Document and agreement restore - report only (nothing was changed; use --execute).');

            if ($report['aborted'] !== null) {
                $this->error('The run could not start: '.str_replace('_', ' ', (string) $report['aborted']).'. Nothing was restored.');
            }

            $this->table(['Measure', 'Count'], collect($report['counts'])->map(fn ($n, $k) => [str_replace('_', ' ', $k), (string) $n])->values()->all());

            if ($this->option('details') && $report['failures'] !== []) {
                $this->table(['File', 'Owner', 'Needs attention because'], collect($report['failures'])->map(fn ($f) => isset($f['agreement_id'])
                    ? ['agreement '.$f['agreement_id'], 'company '.$f['client_company_id'], $f['reason']]
                    : ['document '.$f['document_id'], 'employee '.$f['employee_id'], $f['reason']])->all());
            }

            if ($report['needs_attention'] > 0) {
                $this->warn('Documents listed as "not in mirror" cannot be recovered here: the employee must provide them again.');
            }
        }

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
