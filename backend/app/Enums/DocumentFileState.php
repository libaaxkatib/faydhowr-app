<?php

namespace App\Enums;

/**
 * What a read-only look at the stored file behind an employee-document record found. Used by the download endpoint (to
 * answer 404 vs 503 instead of a server error) and by the `hr:documents-inventory` command.
 */
enum DocumentFileState: string
{
    /** The file exists, can be opened, and its size matches the recorded size. */
    case Verified = 'verified';

    /** The file exists and can be opened, but its size differs from the size recorded at upload. */
    case SizeMismatch = 'size_mismatch';

    /** The storage is reachable, but there is no file at the recorded location. */
    case Missing = 'missing';

    /** The recorded location is empty or is not a path this application would ever have written. */
    case InvalidReference = 'invalid_reference';

    /** The file seems to exist but could not be opened or measured (permission or I/O problem). */
    case Unreadable = 'unreadable';

    /** The storage itself could not be reached or checked, so nothing can be said about the file. */
    case StorageUnavailable = 'storage_unavailable';

    /** Can the file be sent to the user? A size difference alone does not stop a download. */
    public function isServable(): bool
    {
        return $this === self::Verified || $this === self::SizeMismatch;
    }

    /** The file is confirmed absent (or its reference is unusable): the right answer is "not found". */
    public function isPermanentlyUnavailable(): bool
    {
        return $this === self::Missing || $this === self::InvalidReference;
    }

    /** The state says nothing reliable about the file: the right answer is "try again later". */
    public function isTemporaryFailure(): bool
    {
        return $this === self::Unreadable || $this === self::StorageUnavailable;
    }
}
