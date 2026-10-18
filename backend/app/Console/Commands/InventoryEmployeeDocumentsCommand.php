<?php

namespace App\Console\Commands;

use App\Enums\DocumentFileState;
use App\Models\EmployeeDocument;
use App\Support\Hr\Documents\DocumentFileInspector;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Read-only inventory of employee-document records against the files that back them.
 *
 * It never writes: no database row, no file, no log entry in the audit trail is created, changed, moved, repaired or
 * deleted, so it is safe to run repeatedly. Output is counts by default; `--details` adds one line per problem using
 * internal ids only (no file names, paths, contents, links or personal data). Each record is checked on the disk it names
 * (older documents: 'local'); `--disk` limits the run to one disk. `--checksums` also reads and hashes every file that has a
 * recorded SHA-256 (slower; a difference is reported as checksum_mismatch).
 */
class InventoryEmployeeDocumentsCommand extends Command
{
    protected $signature = 'hr:documents-inventory
        {--disk= : Only check the records stored on this disk (default: every disk the records name)}
        {--checksums : Also read and hash each file that has a recorded SHA-256 (slower)}
        {--details : List each problem record / unreferenced file by internal id (no names or paths)}
        {--skip-orphans : Do not look for stored files that no record refers to}
        {--fail-on-issues : Exit with a failure code when any missing, invalid, unreadable, mismatched or unreferenced file is found}
        {--json : Print the report as JSON}';

    protected $description = 'Read-only: compare employee-document records with their stored files (counts only unless --details).';

    /** Same shape the application writes: employees/{id}/documents/{generated name}. Only this folder is ever scanned. */
    private const FILE_PATTERN = '#\Aemployees/(\d+)/documents/[A-Za-z0-9._-]+\z#';

    public function handle(): int
    {
        $requested = $this->option('disk');
        $disks = $requested !== null && $requested !== ''
            ? [(string) $requested]
            : (EmployeeDocument::query()->distinct()->orderBy('disk')->pluck('disk')->map(fn ($d) => (string) $d)->all() ?: ['local']);

        foreach ($disks as $diskName) {
            if (! is_array(config("filesystems.disks.{$diskName}"))) {
                $this->error("Unknown storage disk '{$diskName}'.");

                return self::FAILURE;
            }

            // If the storage cannot be reached, say so rather than reporting every record as missing.
            if (! (new DocumentFileInspector($diskName))->isStorageAvailable()) {
                $this->error('The document storage is not reachable, so no file could be checked. Nothing was reported as missing.');

                return self::FAILURE;
            }
        }

        $summary = [
            'total_records' => 0,
            'current_records' => 0,
            'superseded_records' => 0,
            DocumentFileState::Verified->value => 0,
            DocumentFileState::SizeMismatch->value => 0,
            DocumentFileState::Missing->value => 0,
            DocumentFileState::InvalidReference->value => 0,
            DocumentFileState::ChecksumMismatch->value => 0,
            DocumentFileState::Unreadable->value => 0,
            DocumentFileState::StorageUnavailable->value => 0,
            'inspection_errors' => 0,
            'without_recorded_checksum' => 0,
        ];
        $problems = [];
        $orphans = [];
        $orphanScanned = 0;
        $scanOrphans = ! $this->option('skip-orphans');
        $withChecksums = (bool) $this->option('checksums');

        foreach ($disks as $diskName) {
            $inspector = new DocumentFileInspector($diskName);
            $referenced = [];

            EmployeeDocument::query()
                ->where('disk', $diskName)
                ->select(['id', 'employee_id', 'disk', 'file_path', 'file_size', 'sha256', 'is_current', 'created_at'])
                ->chunkById(200, function ($documents) use ($inspector, $withChecksums, &$summary, &$problems, &$referenced): void {
                    foreach ($documents as $document) {
                        $summary['total_records']++;
                        $document->is_current ? $summary['current_records']++ : $summary['superseded_records']++;

                        if ($document->sha256 === null) {
                            $summary['without_recorded_checksum']++;
                        }

                        if (is_string($document->file_path) && $document->file_path !== '') {
                            $referenced[$document->file_path] = true;
                        }

                        try {
                            $state = $inspector->inspect($document->file_path, (int) $document->file_size, $withChecksums ? $document->sha256 : null);
                        } catch (Throwable) {
                            $summary['inspection_errors']++;
                            $problems[] = $this->problem($document, 'inspection_error');

                            continue;
                        }

                        $summary[$state->value]++;

                        if ($state !== DocumentFileState::Verified) {
                            $problems[] = $this->problem($document, $state->value);
                        }
                    }
                });

            if ($scanOrphans) {
                try {
                    [$found, $scanned] = $this->findUnreferencedFiles($diskName, $referenced);
                    $orphans = array_merge($orphans, $found);
                    $orphanScanned += $scanned;
                } catch (Throwable) {
                    $summary['inspection_errors']++;
                }
            }
        }

        $summary['unreferenced_files'] = $scanOrphans ? count($orphans) : null;
        $summary['files_scanned_for_unreferenced'] = $scanOrphans ? $orphanScanned : null;

        $issues = $summary[DocumentFileState::SizeMismatch->value]
            + $summary[DocumentFileState::Missing->value]
            + $summary[DocumentFileState::InvalidReference->value]
            + $summary[DocumentFileState::ChecksumMismatch->value]
            + $summary[DocumentFileState::Unreadable->value]
            + $summary[DocumentFileState::StorageUnavailable->value]
            + (int) ($summary['unreferenced_files'] ?? 0);

        // A reachable storage that holds none of the recorded files is usually the wrong (or an empty, freshly attached) storage,
        // not a million separate losses - say so, without changing how the files are counted.
        $warnings = [];

        if ($summary['total_records'] > 0 && $summary[DocumentFileState::Missing->value] === $summary['total_records']) {
            $warnings[] = 'Every record points at a file that is not in this storage. If that is unexpected, check that the right storage is attached before treating the files as lost.';
        }

        $details = (bool) $this->option('details');

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                'disk' => count($disks) === 1 ? $disks[0] : implode(',', $disks),
                'read_only' => true,
                'warnings' => $warnings,
                'summary' => $summary,
                'problem_records' => $details ? $problems : null,
                'unreferenced_files' => $details ? ($scanOrphans ? $orphans : null) : null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->render($summary, $details ? $problems : null, $details && $scanOrphans ? $orphans : null);

            foreach ($warnings as $warning) {
                $this->warn($warning);
            }
        }

        if ($summary['inspection_errors'] > 0) {
            return self::FAILURE;
        }

        return $this->option('fail-on-issues') && $issues > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{document_id: int, employee_id: int, disk: string, current: bool, uploaded_on: string|null, problem: string} */
    private function problem(EmployeeDocument $document, string $problem): array
    {
        return [
            'document_id' => (int) $document->id,
            'employee_id' => (int) $document->employee_id,
            'disk' => (string) $document->disk,
            'current' => (bool) $document->is_current,
            'uploaded_on' => $document->created_at?->toDateString(),
            'problem' => $problem,
        ];
    }

    /**
     * Only `employees/{id}/documents/*` is listed - never any other private or system folder - and a file counts as
     * unreferenced only when no record (current or superseded) on that disk points at it. Nothing is touched.
     *
     * @param  array<string, true>  $referenced
     * @return array{0: list<array{disk: string, employee_id: int, size_bytes: int|null, modified_on: string|null, reference: string}>, 1: int}
     */
    private function findUnreferencedFiles(string $diskName, array $referenced): array
    {
        $disk = Storage::disk($diskName);

        if (! $disk->exists('employees')) {
            return [[], 0];
        }

        $scanned = 0;
        $orphans = [];

        foreach ($disk->allFiles('employees') as $path) {
            if (preg_match(self::FILE_PATTERN, $path, $match) !== 1) {
                continue;
            }

            $scanned++;

            if (isset($referenced[$path])) {
                continue;
            }

            $modified = null;
            $size = null;

            try {
                $modified = CarbonImmutable::createFromTimestampUTC($disk->lastModified($path))->toDateString();
                $size = $disk->size($path);
            } catch (Throwable) {
                // Metadata is optional context; the file is still listed.
            }

            $orphans[] = [
                'disk' => $diskName,
                'employee_id' => (int) $match[1],
                'size_bytes' => $size,
                'modified_on' => $modified,
                // A short fingerprint that lets an operator match this line to a file without printing the path.
                'reference' => substr(hash('sha256', $path), 0, 12),
            ];
        }

        return [$orphans, $scanned];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>|null  $problems
     * @param  list<array<string, mixed>>|null  $orphans
     */
    private function render(array $summary, ?array $problems, ?array $orphans): void
    {
        $this->info('Employee document inventory (read-only - nothing was changed).');
        $this->table(['Measure', 'Count'], collect($summary)->map(fn ($value, $key) => [
            str_replace('_', ' ', $key),
            $value === null ? 'not checked' : (string) $value,
        ])->values()->all());

        if ($problems !== null) {
            $this->line('');
            $this->info('Records with a problem (internal ids only):');
            $this->table(['Document', 'Employee', 'Disk', 'Current', 'Uploaded', 'Problem'], collect($problems)->map(fn ($p) => [
                $p['document_id'], $p['employee_id'], $p['disk'], $p['current'] ? 'yes' : 'no', $p['uploaded_on'] ?? '-', $p['problem'],
            ])->all());
        }

        if ($orphans !== null) {
            $this->line('');
            $this->info('Stored files that no record refers to (employee id and short fingerprint only):');
            $this->table(['Disk', 'Employee', 'Size (bytes)', 'Modified', 'Fingerprint'], collect($orphans)->map(fn ($o) => [
                $o['disk'], $o['employee_id'], $o['size_bytes'] ?? '-', $o['modified_on'] ?? '-', $o['reference'],
            ])->all());
        }

        if ($problems === null) {
            $this->line('Run with --details to list the affected records by internal id.');
        }
    }
}
