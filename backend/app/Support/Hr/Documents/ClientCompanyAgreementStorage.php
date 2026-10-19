<?php

namespace App\Support\Hr\Documents;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Private storage for client-company agreements. It reuses the verified write of employee documents (size and SHA-256
 * read back before any row exists), and adds the one rule that matters for these files: they are never kept on an
 * ephemeral application disk and never silently moved to a different disk.
 *
 * The disk is `filesystems.hr_attachments_disk`. Outside production the private local disk is fine (development and tests).
 * In production a local disk lives inside the application container and is lost at the next deploy, so an upload is
 * refused with a clear storage error instead; an unknown disk name is refused the same way. Nothing falls back to 'local'.
 */
final class ClientCompanyAgreementStorage
{
    public function __construct(private readonly EmployeeDocumentStorage $storage) {}

    /** The configured disk, or a DocumentStorageException when it is missing, unknown or not durable in production. */
    public function disk(): string
    {
        $disk = trim((string) config('filesystems.hr_attachments_disk', ''));

        if ($disk === '') {
            throw DocumentStorageException::from('agreement_disk_not_configured');
        }

        $config = config("filesystems.disks.{$disk}");

        if (is_array($config)) {
            if (($config['driver'] ?? null) === 'local' && app()->environment('production')) {
                throw DocumentStorageException::from('agreement_disk_not_durable');
            }

            return $disk;
        }

        // Not in the configuration: only acceptable if the storage manager can still resolve it (for example a disk
        // registered at runtime). An unknown name must fail here, not later with a raw framework error.
        try {
            Storage::disk($disk);
        } catch (Throwable $e) {
            throw DocumentStorageException::from('agreement_disk_not_configured', $e);
        }

        return $disk;
    }

    public function store(UploadedFile $file, int $clientCompanyId): StoredDocumentFile
    {
        $disk = $this->disk();

        return $this->storage->storeAt($file, $disk, 'client-companies/'.$clientCompanyId.'/agreements/'.$file->hashName());
    }

    /** Removes the object THIS request just wrote (used when the database step after the write fails). */
    public function discard(StoredDocumentFile $stored): void
    {
        $this->storage->discard($stored);
    }
}
