<?php

namespace Tests\Support;

use Illuminate\Filesystem\FilesystemAdapter as LaravelDisk;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use Mockery;
use RuntimeException;

/**
 * Test helpers for the employee-document disks.
 *
 * `Storage::fake()` swaps the disk object but leaves the configured root pointing at the real storage folder, so these
 * helpers align the configuration with the fake folder, and simulate an unreachable storage by pointing the
 * configuration at a folder that does not exist. They never delete, clear or recreate anything, and they refuse to run
 * if the root they would use is, or lies inside, the real application storage (`storage/app`).
 */
trait FakesDocumentDisk
{
    /** @param  array<string, mixed>  $diskConfig  extra disk settings, e.g. ['throw' => false] to mirror production */
    private function fakeLocalDisk(array $diskConfig = []): void
    {
        $this->fakeDisk('local', $diskConfig);
    }

    /**
     * An isolated fake disk with this name (for example a second "primary" disk or a recovery mirror), registered in the
     * configuration so code that looks the disk up by name sees it.
     *
     * @param  array<string, mixed>  $diskConfig
     */
    private function fakeDisk(string $name, array $diskConfig = []): void
    {
        Storage::fake($name, $diskConfig);

        $root = rtrim(Storage::disk($name)->path(''), '/\\');
        $this->refuseUnlessIsolated($root);

        config(["filesystems.disks.{$name}" => array_merge(
            (array) config("filesystems.disks.{$name}", []),
            ['driver' => 'local', 'root' => $root, 'throw' => false],
        )]);
    }

    /**
     * Registers a named disk backed by an in-memory object store (an S3-like disk that is not local, so it has no folder to
     * check) - for storage faults that a real folder cannot simulate.
     */
    private function useObjectStore(string $name, InMemoryObjectStore $store): InMemoryObjectStore
    {
        config(["filesystems.disks.{$name}" => ['driver' => 's3', 'bucket' => 'in-memory']]);
        Storage::set($name, new LaravelDisk($store, Mockery::mock(FlysystemAdapter::class), ['driver' => 's3']));

        return $store;
    }

    private function makeStorageUnreachable(): void
    {
        $this->makeDiskUnreachable('local');
    }

    /** Points the named disk's configured root at a folder that does not exist (never creates or deletes anything). */
    private function makeDiskUnreachable(string $name): void
    {
        $root = storage_path('framework/testing/disks/this-folder-does-not-exist-'.$name);
        $this->refuseUnlessIsolated($root);

        config(["filesystems.disks.{$name}.root" => $root]);
    }

    private function refuseUnlessIsolated(string $root): void
    {
        $normalise = static fn (string $path): string => rtrim(str_replace('\\', '/', $path), '/').'/';
        $candidate = $normalise($root);
        $realStorage = $normalise(storage_path('app'));
        $testingArea = $normalise(storage_path('framework/testing'));

        if (str_starts_with($candidate, $realStorage) || str_starts_with($realStorage, $candidate) || ! str_starts_with($candidate, $testingArea)) {
            throw new RuntimeException('Refusing to use a test storage root that is not isolated from the real application storage.');
        }
    }
}
