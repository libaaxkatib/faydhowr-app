<?php

namespace App\Support\Hr\Documents;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use Throwable;

/**
 * Writes a newly uploaded employee document to the configured private disk and proves it arrived intact.
 *
 * Every filesystem call goes through the Flysystem driver directly, which always throws on failure regardless of the
 * disk's `throw` setting. A document is only reported as stored after (1) the write returned, (2) the stored size equals the
 * uploaded size and (3) re-reading the stored object gives the same SHA-256 as the upload. Anything else removes the
 * object this call created (never anything it did not create) and raises a DocumentStorageException, so the caller can
 * roll back and no database row ever points at a file that is missing, cut short or different.
 *
 * Which disk is used comes from configuration (`filesystems.hr_documents_disk`, default 'local' = today's behaviour); the
 * disk actually used is returned so it can be recorded on the document row.
 */
final class EmployeeDocumentStorage
{
    public function disk(): string
    {
        return (string) config('filesystems.hr_documents_disk', 'local');
    }

    public function store(UploadedFile $file, int $employeeId): StoredDocumentFile
    {
        $disk = $this->disk();
        $source = (string) $file->getRealPath();

        if ($source === '' || ! is_file($source)) {
            throw DocumentStorageException::from('upload_unreadable');
        }

        $sha256 = (string) hash_file('sha256', $source);
        $size = (int) filesize($source);
        $path = 'employees/'.$employeeId.'/documents/'.$file->hashName();

        if (! DocumentFileInspector::isUsableReference($path)) {
            throw DocumentStorageException::from('unusable_generated_name');
        }

        if (! (new DocumentFileInspector($disk))->isStorageAvailable()) {
            throw DocumentStorageException::from('storage_unavailable');
        }

        $driver = null;
        $created = false;

        try {
            $driver = $this->driver($disk);

            if ($driver->fileExists($path)) {
                // Names are random, so this should never happen; never overwrite whatever is there.
                throw DocumentStorageException::from('name_collision');
            }

            $input = fopen($source, 'rb');

            if ($input === false) {
                throw DocumentStorageException::from('upload_unreadable');
            }

            try {
                $created = true; // from here on an object may exist that this call created
                $driver->writeStream($path, $input);
            } finally {
                fclose($input);
            }

            if ($driver->fileSize($path) !== $size) {
                throw DocumentStorageException::from('size_mismatch_after_write');
            }

            if ((bool) config('filesystems.hr_documents_verify_readback', true)) {
                $stored = $driver->readStream($path);
                $storedHash = DocumentFileInspector::hashStream($stored);
                is_resource($stored) && fclose($stored);

                if (! hash_equals($sha256, $storedHash)) {
                    throw DocumentStorageException::from('checksum_mismatch_after_write');
                }
            }
        } catch (Throwable $e) {
            if ($created && $driver instanceof FilesystemOperator) {
                $this->discardObject($driver, $path);
            }

            throw $e instanceof DocumentStorageException ? $e : DocumentStorageException::from('write_failed', $e);
        }

        return new StoredDocumentFile($disk, $path, $size, $sha256);
    }

    /** Best-effort removal of an object THIS request created (used when the database step after the write fails). */
    public function discard(StoredDocumentFile $stored): void
    {
        try {
            $this->discardObject($this->driver($stored->disk), $stored->path);
        } catch (Throwable) {
            Log::warning('A just-written employee document could not be cleaned up after a failed save.', ['reason' => 'discard_failed']);
        }
    }

    private function discardObject(FilesystemOperator $driver, string $path): void
    {
        try {
            if ($driver->fileExists($path)) {
                $driver->delete($path);
            }
        } catch (Throwable) {
            Log::warning('A partially written employee document could not be cleaned up.', ['reason' => 'discard_failed']);
        }
    }

    private function driver(string $disk): FilesystemOperator
    {
        return Storage::disk($disk)->getDriver();
    }
}
