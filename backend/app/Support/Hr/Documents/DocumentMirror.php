<?php

namespace App\Support\Hr\Documents;

use App\Enums\DocumentFileState;
use App\Models\EmployeeDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use Throwable;

/**
 * A recoverable second copy of employee documents - never a second authority.
 *
 * mirror():  copies every document on the primary disk to the mirror disk, verifying each copy by SHA-256, and writes a dated
 *            manifest plus a small "last run" file onto the mirror disk so its freshness can be monitored.
 * restore(): copies a verified mirror object back to the primary disk, but only where the primary object is confirmed missing.
 * freshness(): reads the "last run" file and says whether the mirror is recent and failure-free.
 *
 * Safety rules, each covered by a test:
 *  - The primary disk is only ever read, except by restore() filling a gap that is confirmed missing; it is never overwritten.
 *  - A mirror object that already exists is never overwritten or removed: identical -> "already present", different -> a
 *    reported conflict. The ONLY delete in this class is discardPartial(), which removes an object THIS run just wrote and
 *    failed to verify (the path did not exist before the run), so a cut-short copy cannot masquerade as a backup.
 *  - A source file whose contents do not match its recorded checksum is NOT copied (a damaged file must not become the backup).
 *  - Nothing in the database is changed, and nothing is reported as copied/restored until its checksum has been read back.
 *  - Transient read/write failures are retried; what still fails is reported, never hidden.
 * Reports contain internal ids and counts only - no file names, paths or contents.
 */
final class DocumentMirror
{
    public const META_DIR = '_mirror';

    public const LAST_RUN = '_mirror/last-run.json';

    public function __construct(
        private readonly string $primaryDisk,
        private readonly string $mirrorDisk,
        private readonly int $attempts = 3,
        private readonly int $backoffMilliseconds = 250,
    ) {}

    /** @return array<string, mixed> */
    public function mirror(bool $dryRun = false, bool $deep = false): array
    {
        $counts = array_fill_keys(['considered', 'copied', 'already_present', 'would_copy', 'source_missing', 'source_unavailable', 'source_checksum_mismatch', 'mirror_conflict', 'copy_failed', 'invalid_reference', 'without_recorded_checksum', 'manifest_failed'], 0);
        $failures = [];
        $entries = [];
        $startedAt = CarbonImmutable::now('UTC');

        $aborted = $this->abortReason();

        if ($aborted === null) {
            try {
                $mirrorDriver = $this->driver($this->mirrorDisk);

                EmployeeDocument::query()
                    ->where('disk', $this->primaryDisk)
                    ->select(['id', 'employee_id', 'disk', 'file_path', 'file_size', 'sha256'])
                    ->chunkById(100, function ($documents) use ($mirrorDriver, $dryRun, $deep, &$counts, &$failures, &$entries): void {
                        foreach ($documents as $document) {
                            $this->mirrorOne($document, $mirrorDriver, $dryRun, $deep, $counts, $failures, $entries);
                        }
                    });
            } catch (Throwable) {
                $aborted = 'mirror_unavailable';
            }
        }

        $finishedAt = CarbonImmutable::now('UTC');
        $failed = $this->failureTotal($counts);

        if ($aborted === null && ! $dryRun) {
            $counts['manifest_failed'] = $this->writeManifests($startedAt, $finishedAt, $counts, $failed, $entries) ? 0 : 1;
            $failed = $this->failureTotal($counts);
        }

        return [
            'primary_disk' => $this->primaryDisk,
            'mirror_disk' => $this->mirrorDisk,
            'dry_run' => $dryRun,
            'deep' => $deep,
            'aborted' => $aborted,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt->toIso8601String(),
            'counts' => $counts,
            'failed_total' => $failed,
            'failures' => $failures,
            'ok' => $aborted === null && $failed === 0,
        ];
    }

    /**
     * @param  list<int>|null  $onlyDocumentIds
     * @return array<string, mixed>
     */
    public function restore(bool $execute, ?array $onlyDocumentIds = null): array
    {
        $counts = array_fill_keys(['considered', 'present', 'restorable', 'restored', 'not_in_mirror', 'mirror_checksum_mismatch', 'unverifiable_no_recorded_checksum', 'restore_failed', 'primary_unavailable', 'invalid_reference'], 0);
        $failures = [];
        $aborted = $this->abortReason();

        if ($aborted === null) {
            try {
                $primaryInspector = new DocumentFileInspector($this->primaryDisk);
                $primaryDriver = $this->driver($this->primaryDisk);
                $mirrorDriver = $this->driver($this->mirrorDisk);

                EmployeeDocument::query()
                    ->where('disk', $this->primaryDisk)
                    ->when($onlyDocumentIds !== null, fn ($q) => $q->whereIn('id', $onlyDocumentIds))
                    ->select(['id', 'employee_id', 'disk', 'file_path', 'file_size', 'sha256'])
                    ->chunkById(100, function ($documents) use ($primaryInspector, $primaryDriver, $mirrorDriver, $execute, &$counts, &$failures): void {
                        foreach ($documents as $document) {
                            $this->restoreOne($document, $primaryInspector, $primaryDriver, $mirrorDriver, $execute, $counts, $failures);
                        }
                    });
            } catch (Throwable) {
                $aborted = 'storage_unavailable';
            }
        }

        $problems = $counts['not_in_mirror'] + $counts['mirror_checksum_mismatch'] + $counts['unverifiable_no_recorded_checksum'] + $counts['restore_failed'] + $counts['primary_unavailable'] + $counts['invalid_reference'];

        return [
            'primary_disk' => $this->primaryDisk,
            'mirror_disk' => $this->mirrorDisk,
            'executed' => $execute,
            'aborted' => $aborted,
            'counts' => $counts,
            'needs_attention' => $problems,
            'failures' => $failures,
            'ok' => $aborted === null && $problems === 0,
        ];
    }

    /** @return array{ok: bool, reason: string|null, finished_at: string|null, age_hours: float|null, failed_total: int|null} */
    public function freshness(int $maxAgeHours): array
    {
        $result = ['ok' => false, 'reason' => null, 'finished_at' => null, 'age_hours' => null, 'failed_total' => null];

        try {
            $driver = $this->driver($this->mirrorDisk);

            if (! (new DocumentFileInspector($this->mirrorDisk))->isStorageAvailable()) {
                return [...$result, 'reason' => 'mirror_unavailable'];
            }

            if (! $driver->fileExists(self::LAST_RUN)) {
                return [...$result, 'reason' => 'no_run_recorded'];
            }

            $data = json_decode($driver->read(self::LAST_RUN), true);
        } catch (Throwable) {
            return [...$result, 'reason' => 'mirror_unavailable'];
        }

        $finishedAt = is_array($data) ? ($data['finished_at'] ?? null) : null;

        if (! is_string($finishedAt)) {
            return [...$result, 'reason' => 'unreadable_run_record'];
        }

        $age = CarbonImmutable::parse($finishedAt)->diffInSeconds(CarbonImmutable::now('UTC'), false) / 3600;
        $failedTotal = (int) ($data['failed_total'] ?? 0);
        $result = ['ok' => false, 'reason' => null, 'finished_at' => $finishedAt, 'age_hours' => round($age, 2), 'failed_total' => $failedTotal];

        if ($age > $maxAgeHours) {
            return [...$result, 'reason' => 'stale'];
        }

        if ($failedTotal > 0 || ($data['aborted'] ?? null) !== null) {
            return [...$result, 'reason' => 'last_run_had_failures'];
        }

        return [...$result, 'ok' => true];
    }

    /** The reason the run cannot even start, or null. Never guesses: an unreachable disk is not an empty disk. */
    private function abortReason(): ?string
    {
        if ($this->primaryDisk === $this->mirrorDisk) {
            return 'mirror_is_the_primary_disk';
        }

        if (! (new DocumentFileInspector($this->primaryDisk))->isStorageAvailable()) {
            return 'primary_unavailable';
        }

        if (! (new DocumentFileInspector($this->mirrorDisk))->isStorageAvailable()) {
            return 'mirror_unavailable';
        }

        return null;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $failures
     * @param  list<array<string, mixed>>  $entries
     */
    private function mirrorOne(EmployeeDocument $document, FilesystemOperator $mirrorDriver, bool $dryRun, bool $deep, array &$counts, array &$failures, array &$entries): void
    {
        $counts['considered']++;
        $path = (string) $document->file_path;
        $fail = function (string $reason, string $counter) use ($document, &$counts, &$failures): void {
            $counts[$counter]++;
            $failures[] = ['document_id' => (int) $document->id, 'employee_id' => (int) $document->employee_id, 'reason' => $reason];
        };

        if (! DocumentFileInspector::isUsableReference($path)) {
            $fail('invalid_reference', 'invalid_reference');

            return;
        }

        $recorded = $document->sha256 !== null ? strtolower((string) $document->sha256) : null;

        if ($recorded === null) {
            $counts['without_recorded_checksum']++;
        }

        $primary = new DocumentFileInspector($this->primaryDisk);

        // Routine runs stay cheap: a document whose mirror object already exists with the recorded size is accepted without
        // downloading or hashing anything (the source is only checked to still exist and be readable). A deep run (--deep, for
        // example weekly) re-reads and re-hashes everything; a same-size substitution is only caught there.
        if (! $deep) {
            try {
                $mirrorHasIt = $mirrorDriver->fileExists($path) && $mirrorDriver->fileSize($path) === (int) $document->file_size;
            } catch (Throwable) {
                $mirrorHasIt = false; // fall through to the full path, which retries and reports honestly
            }

            if ($mirrorHasIt) {
                $state = $primary->inspect($path);

                if ($state === DocumentFileState::Missing || $state === DocumentFileState::InvalidReference) {
                    $fail('source_missing', 'source_missing');
                } elseif ($state->isTemporaryFailure()) {
                    $fail('source_unavailable', 'source_unavailable');
                } else {
                    $counts['already_present']++;
                    $entries[] = $this->entry($document, $recorded, (int) $document->file_size, 'already_present');
                }

                return;
            }
        }

        // 1. Read the source once into a temporary stream, hashing as we go. A transient failure is retried; "missing" is not.
        $temp = null;

        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $opened = $primary->open($path);

            if (is_array($opened)) {
                $temp = fopen('php://temp/maxmemory:5242880', 'w+b');
                stream_copy_to_stream($opened['stream'], $temp);
                fclose($opened['stream']);
                break;
            }

            if ($opened === DocumentFileState::Missing || $opened === DocumentFileState::InvalidReference) {
                $fail('source_missing', 'source_missing');

                return;
            }

            if ($attempt < $this->attempts) {
                $this->pause();
            }
        }

        if ($temp === null) {
            $fail('source_unavailable', 'source_unavailable');

            return;
        }

        rewind($temp);
        $sourceSha = DocumentFileInspector::hashStream($temp);
        $size = (int) (fstat($temp)['size'] ?? 0);

        // 2. A damaged source must never become the backup.
        if ($recorded !== null && ! hash_equals($recorded, $sourceSha)) {
            fclose($temp);
            $fail('source_checksum_mismatch', 'source_checksum_mismatch');

            return;
        }

        $expected = $recorded ?? $sourceSha;

        // 3. Copy (or recognise an identical copy), verifying what was written.
        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $created = false;

            try {
                if ($mirrorDriver->fileExists($path)) {
                    $existing = $mirrorDriver->readStream($path);
                    $existingSha = DocumentFileInspector::hashStream($existing);
                    is_resource($existing) && fclose($existing);

                    if (hash_equals($expected, $existingSha)) {
                        $counts['already_present']++;
                        $entries[] = $this->entry($document, $expected, $size, 'already_present');
                    } else {
                        $fail('mirror_conflict', 'mirror_conflict'); // never overwritten, never removed
                    }

                    fclose($temp);

                    return;
                }

                if ($dryRun) {
                    $counts['would_copy']++;
                    fclose($temp);

                    return;
                }

                rewind($temp);
                $created = true;
                $mirrorDriver->writeStream($path, $temp);

                $copy = $mirrorDriver->readStream($path);
                $copySha = DocumentFileInspector::hashStream($copy);
                is_resource($copy) && fclose($copy);

                if ($mirrorDriver->fileSize($path) !== $size || ! hash_equals($expected, $copySha)) {
                    throw new \RuntimeException('mirror copy did not verify');
                }

                $counts['copied']++;
                $entries[] = $this->entry($document, $expected, $size, 'copied');
                fclose($temp);

                return;
            } catch (Throwable) {
                if ($created) {
                    $this->discardPartial($mirrorDriver, $path);
                }

                if ($attempt < $this->attempts) {
                    $this->pause();
                }
            }
        }

        is_resource($temp) && fclose($temp);
        $fail('copy_failed', 'copy_failed');
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $failures
     */
    private function restoreOne(EmployeeDocument $document, DocumentFileInspector $primaryInspector, FilesystemOperator $primaryDriver, FilesystemOperator $mirrorDriver, bool $execute, array &$counts, array &$failures): void
    {
        $counts['considered']++;
        $path = (string) $document->file_path;
        $note = function (string $reason, string $counter) use ($document, &$counts, &$failures): void {
            $counts[$counter]++;
            $failures[] = ['document_id' => (int) $document->id, 'employee_id' => (int) $document->employee_id, 'reason' => $reason];
        };

        $state = $primaryInspector->inspect($path);

        if ($state === DocumentFileState::InvalidReference) {
            $note('invalid_reference', 'invalid_reference');

            return;
        }

        if ($state->isTemporaryFailure()) {
            $note('primary_unavailable', 'primary_unavailable'); // never restore over something we could not check

            return;
        }

        if ($state !== DocumentFileState::Missing) {
            $counts['present']++; // there is an object: restore never overwrites it

            return;
        }

        try {
            if (! $mirrorDriver->fileExists($path)) {
                $note('not_in_mirror', 'not_in_mirror'); // the employee must provide the document again

                return;
            }

            if ($document->sha256 === null) {
                $note('unverifiable_no_recorded_checksum', 'unverifiable_no_recorded_checksum');

                return;
            }

            $temp = fopen('php://temp/maxmemory:5242880', 'w+b');
            $source = $mirrorDriver->readStream($path);
            stream_copy_to_stream($source, $temp);
            is_resource($source) && fclose($source);
            rewind($temp);
            $mirrorSha = DocumentFileInspector::hashStream($temp);
            $size = (int) (fstat($temp)['size'] ?? 0);

            if (! hash_equals(strtolower((string) $document->sha256), $mirrorSha)) {
                fclose($temp);
                $note('mirror_checksum_mismatch', 'mirror_checksum_mismatch');

                return;
            }

            if (! $execute) {
                fclose($temp);
                $counts['restorable']++;

                return;
            }

            if ($primaryDriver->fileExists($path)) {
                fclose($temp);
                $counts['present']++; // appeared in the meantime: leave it alone

                return;
            }

            rewind($temp);
            $created = true;

            try {
                $primaryDriver->writeStream($path, $temp);
                $written = $primaryDriver->readStream($path);
                $writtenSha = DocumentFileInspector::hashStream($written);
                is_resource($written) && fclose($written);

                if ($primaryDriver->fileSize($path) !== $size || ! hash_equals($mirrorSha, $writtenSha)) {
                    throw new \RuntimeException('restored copy did not verify');
                }
            } catch (Throwable) {
                $created && $this->discardPartial($primaryDriver, $path);
                is_resource($temp) && fclose($temp);
                $note('restore_failed', 'restore_failed');

                return;
            }

            fclose($temp);
            $counts['restored']++;
        } catch (Throwable) {
            $note('restore_failed', 'restore_failed');
        }
    }

    /**
     * Writes the dated manifest (never overwriting an earlier one) and the small "last run" file.
     *
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $entries
     */
    private function writeManifests(CarbonImmutable $startedAt, CarbonImmutable $finishedAt, array $counts, int $failed, array $entries): bool
    {
        $driver = null;
        $path = null;
        $manifestWritten = false;

        try {
            $driver = $this->driver($this->mirrorDisk);
            $base = self::META_DIR.'/manifests/'.$finishedAt->format('Ymd\THis\Z');
            $path = $base.'.json';

            for ($suffix = 2; $driver->fileExists($path); $suffix++) {
                $path = $base.'-'.$suffix.'.json';
            }

            $summary = [
                'primary_disk' => $this->primaryDisk,
                'started_at' => $startedAt->toIso8601String(),
                'finished_at' => $finishedAt->toIso8601String(),
                'counts' => $counts,
                'failed_total' => $failed,
                'aborted' => null,
            ];

            $driver->write($path, (string) json_encode([...$summary, 'entries' => $entries], JSON_UNESCAPED_SLASHES));
            $manifestWritten = true;
            $driver->write(self::LAST_RUN, (string) json_encode([...$summary, 'manifest' => basename($path)], JSON_UNESCAPED_SLASHES));

            return true;
        } catch (Throwable) {
            // A full or failing volume can leave an empty or cut-short manifest behind (seen on a real volume). Remove only the
            // file THIS call picked because its name was free; last-run.json is never touched here and keeps the previous run.
            if ($driver instanceof FilesystemOperator && $path !== null && ! $manifestWritten) {
                $this->discardPartial($driver, $path);
            }

            return false;
        }
    }

    /** @param array<string, int> $counts */
    private function failureTotal(array $counts): int
    {
        return $counts['source_missing'] + $counts['source_unavailable'] + $counts['source_checksum_mismatch'] + $counts['mirror_conflict'] + $counts['copy_failed'] + $counts['invalid_reference'] + $counts['manifest_failed'];
    }

    /** @return array{document_id: int, employee_id: int, path: string, sha256: string|null, size: int, status: string} */
    private function entry(EmployeeDocument $document, ?string $sha256, int $size, string $status): array
    {
        return ['document_id' => (int) $document->id, 'employee_id' => (int) $document->employee_id, 'path' => (string) $document->file_path, 'sha256' => $sha256, 'size' => $size, 'status' => $status];
    }

    /** Removes an object this run JUST wrote and could not verify (its path was free before). The only delete in this class. */
    private function discardPartial(FilesystemOperator $driver, string $path): void
    {
        try {
            if ($driver->fileExists($path)) {
                $driver->delete($path);
            }
        } catch (Throwable) {
            // Nothing more can be done; the next run reports it as a conflict instead of trusting it.
        }
    }

    private function pause(): void
    {
        if ($this->backoffMilliseconds > 0) {
            usleep($this->backoffMilliseconds * 1000);
        }
    }

    private function driver(string $disk): FilesystemOperator
    {
        return Storage::disk($disk)->getDriver();
    }
}
