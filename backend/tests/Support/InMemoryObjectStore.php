<?php

namespace Tests\Support;

use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;

/**
 * A tiny in-memory object store that behaves like a Flysystem disk and can be told to misbehave, so storage faults
 * (a cut-short write, a corrupted write, a failing read, a full disk, an outage) are tested without any real storage,
 * bucket or credentials. Everything it keeps is in this object; it never touches the filesystem.
 */
final class InMemoryObjectStore implements FilesystemOperator
{
    /** @var array<string, string> object path => bytes */
    public array $objects = [];

    /** @var list<string> every path passed to delete(), in order */
    public array $deleted = [];

    /** How many times an object's contents were opened for reading. */
    public int $reads = 0;

    /** @var list<string> every path passed to a write, in order */
    public array $writes = [];

    /** The next N writes fail; each leaves a truncated partial object behind first (like a cut connection). */
    public int $failNextWrites = 0;

    /** The next N writes appear to succeed but store different bytes of the SAME length (silent corruption). */
    public int $corruptNextWrites = 0;

    /** The next N writes appear to succeed but store only the first half of the bytes (a silently short write). */
    public int $truncateNextWrites = 0;

    /** The next N reads fail. */
    public int $failNextReads = 0;

    /** Every write fails with nothing stored (a full disk / volume limit). */
    public bool $full = false;

    /** Every operation fails (an outage). */
    public bool $down = false;

    public function fileExists(string $location): bool
    {
        $this->guard($location);

        return array_key_exists($location, $this->objects);
    }

    public function directoryExists(string $location): bool
    {
        $this->guard($location);

        foreach (array_keys($this->objects) as $path) {
            if (str_starts_with($path, rtrim($location, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    public function has(string $location): bool
    {
        return $this->fileExists($location) || $this->directoryExists($location);
    }

    public function read(string $location): string
    {
        $this->guard($location);

        return $this->objects[$location] ?? throw UnableToReadFile::fromLocation($location, 'not found');
    }

    public function readStream(string $location)
    {
        $this->guard($location);
        $this->reads++;

        if ($this->failNextReads > 0) {
            $this->failNextReads--;

            throw UnableToReadFile::fromLocation($location, 'simulated read failure');
        }

        $bytes = $this->objects[$location] ?? throw UnableToReadFile::fromLocation($location, 'not found');
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
    {
        return new DirectoryListing([]);
    }

    public function lastModified(string $path): int
    {
        return time();
    }

    public function fileSize(string $path): int
    {
        $this->guard($path);

        return strlen($this->objects[$path] ?? throw UnableToRetrieveMetadata::fileSize($path, 'not found'));
    }

    public function mimeType(string $path): string
    {
        return 'application/octet-stream';
    }

    public function visibility(string $path): string
    {
        return 'private';
    }

    public function write(string $location, string $contents, array $config = []): void
    {
        $this->store($location, $contents);
    }

    public function writeStream(string $location, $contents, array $config = []): void
    {
        $this->store($location, (string) stream_get_contents($contents));
    }

    public function setVisibility(string $path, string $visibility): void {}

    public function delete(string $location): void
    {
        $this->guard($location);
        $this->deleted[] = $location;
        unset($this->objects[$location]);
    }

    public function deleteDirectory(string $location): void
    {
        throw UnableToDeleteDirectory::atLocation($location, 'a directory delete must never be needed');
    }

    public function createDirectory(string $location, array $config = []): void {}

    public function move(string $source, string $destination, array $config = []): void
    {
        throw UnableToMoveFile::fromLocationTo($source, $destination);
    }

    public function copy(string $source, string $destination, array $config = []): void
    {
        throw UnableToCopyFile::fromLocationTo($source, $destination);
    }

    private function store(string $location, string $bytes): void
    {
        $this->guard($location);
        $this->writes[] = $location;

        if ($this->full) {
            throw UnableToWriteFile::atLocation($location, 'no space left');
        }

        if ($this->failNextWrites > 0) {
            $this->failNextWrites--;
            $this->objects[$location] = substr($bytes, 0, max(0, intdiv(strlen($bytes), 2)));

            throw UnableToWriteFile::atLocation($location, 'simulated cut connection');
        }

        if ($this->corruptNextWrites > 0) {
            $this->corruptNextWrites--;
            $bytes = $bytes === '' ? 'x' : chr(ord($bytes[0]) ^ 1).substr($bytes, 1);
        }

        if ($this->truncateNextWrites > 0) {
            $this->truncateNextWrites--;
            $bytes = substr($bytes, 0, max(0, intdiv(strlen($bytes), 2)));
        }

        $this->objects[$location] = $bytes;
    }

    private function guard(string $location): void
    {
        if ($this->down) {
            throw UnableToReadFile::fromLocation($location, 'simulated outage');
        }
    }
}
