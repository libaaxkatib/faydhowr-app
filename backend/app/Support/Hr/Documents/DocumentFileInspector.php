<?php

namespace App\Support\Hr\Documents;

use App\Enums\DocumentFileState;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemOperator;
use Throwable;

/**
 * Read-only look at the stored file behind an employee-document record.
 *
 * Nothing here writes, moves, repairs or deletes anything, and nothing returned or logged contains the file's contents or
 * location. It deliberately separates "the file is not there" from "the storage could not be checked": the Laravel
 * disks in this project are configured with `throw => false`, which would otherwise turn an I/O problem into a plain
 * `false` (or a half-sent response) and make a temporary outage look like a lost file. Every filesystem call here goes
 * through the Flysystem driver directly, which always throws on failure regardless of that setting.
 */
class DocumentFileInspector
{
    /** Every employee document is written by Laravel to `employees/{employee id}/documents/{generated name}`. */
    private const REFERENCE_PATTERN = '#\Aemployees/\d+/documents/[A-Za-z0-9._-]+\z#';

    public function __construct(private readonly string $disk = 'local') {}

    public function disk(): string
    {
        return $this->disk;
    }

    /** A recorded path this application could have produced: no traversal, no absolute path, nothing outside the documents folders. */
    public static function isUsableReference(?string $path): bool
    {
        if ($path === null || $path === '' || strlen($path) > 500) {
            return false;
        }

        if (str_contains($path, "\0") || str_contains($path, '\\') || str_contains($path, '..')) {
            return false;
        }

        return preg_match(self::REFERENCE_PATTERN, $path) === 1;
    }

    /**
     * Can the storage be reached at all? For a local disk this is "does its root folder exist" - when a mounted volume
     * is missing or detached the root vanishes, and every file would otherwise be reported as lost. Checked before the
     * disk object is created, because creating a local disk would silently recreate a missing root folder.
     */
    public function isStorageAvailable(): bool
    {
        $config = config("filesystems.disks.{$this->disk}");

        if (! is_array($config)) {
            return false;
        }

        if (($config['driver'] ?? null) !== 'local') {
            return true;
        }

        $root = $config['root'] ?? null;

        return is_string($root) && $root !== '' && is_dir($root);
    }

    /** What is the state of this file? Reads a little of it to prove it can be opened; never changes anything. */
    public function inspect(?string $path, ?int $expectedSize = null): DocumentFileState
    {
        $problem = $this->locate($path);

        if ($problem !== null) {
            return $problem;
        }

        try {
            $driver = $this->driver();
            $stream = $driver->readStream((string) $path);

            if (! is_resource($stream)) {
                return DocumentFileState::Unreadable;
            }

            fclose($stream);

            $size = $driver->fileSize((string) $path);
        } catch (Throwable) {
            return DocumentFileState::Unreadable;
        }

        if ($expectedSize !== null && $size !== $expectedSize) {
            return DocumentFileState::SizeMismatch;
        }

        return DocumentFileState::Verified;
    }

    /**
     * Opens the file for sending. The handle is returned already open, so a file that disappears afterwards cannot break
     * the transfer half-way; a file that disappears (or fails) before it is opened comes back as the state that explains
     * why, never as an exception.
     *
     * @return array{stream: resource, size: int|null, mime: string}|DocumentFileState
     */
    public function open(?string $path): array|DocumentFileState
    {
        $problem = $this->locate($path);

        if ($problem !== null) {
            return $problem;
        }

        try {
            $driver = $this->driver();
            $stream = $driver->readStream((string) $path);

            if (! is_resource($stream)) {
                return DocumentFileState::Unreadable;
            }
        } catch (Throwable) {
            // Look again: if the file is now confirmed gone it was lost, otherwise the storage failed - never guess "missing".
            $again = $this->locate($path);

            return $again === DocumentFileState::Missing || $again === DocumentFileState::InvalidReference
                ? $again
                : DocumentFileState::Unreadable;
        }

        return [
            'stream' => $stream,
            'size' => $this->optional(fn (): int => $driver->fileSize((string) $path)),
            'mime' => $this->optional(fn (): string => $driver->mimeType((string) $path)) ?? 'application/octet-stream',
        ];
    }

    /** The reason a file cannot be used, or null when it exists at a usable location. */
    private function locate(?string $path): ?DocumentFileState
    {
        if (! self::isUsableReference($path)) {
            return DocumentFileState::InvalidReference;
        }

        try {
            if (! $this->isStorageAvailable()) {
                return DocumentFileState::StorageUnavailable;
            }

            if (! $this->driver()->fileExists((string) $path)) {
                return DocumentFileState::Missing;
            }

            if ($this->resolvesOutsideTheStorage((string) $path)) {
                return DocumentFileState::InvalidReference;
            }
        } catch (Throwable) {
            return DocumentFileState::StorageUnavailable;
        }

        return null;
    }

    /** A link inside the documents folder that points somewhere else on the server is never served. */
    private function resolvesOutsideTheStorage(string $path): bool
    {
        $config = config("filesystems.disks.{$this->disk}");

        if (! is_array($config) || ($config['driver'] ?? null) !== 'local') {
            return false;
        }

        $root = realpath((string) ($config['root'] ?? ''));
        $real = $root === false ? false : realpath($root.DIRECTORY_SEPARATOR.$path);

        return $root !== false && $real !== false && ! str_starts_with($real, $root.DIRECTORY_SEPARATOR);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T|null
     */
    private function optional(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
    }

    private function driver(): FilesystemOperator
    {
        return Storage::disk($this->disk)->getDriver();
    }
}
