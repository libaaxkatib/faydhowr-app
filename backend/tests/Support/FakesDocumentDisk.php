<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Test helpers for the employee-document disk.
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
        Storage::fake('local', $diskConfig);

        $root = rtrim(Storage::disk('local')->path(''), '/\\');
        $this->refuseUnlessIsolated($root);

        config(['filesystems.disks.local.root' => $root]);
    }

    private function makeStorageUnreachable(): void
    {
        $root = storage_path('framework/testing/disks/this-folder-does-not-exist');
        $this->refuseUnlessIsolated($root);

        config(['filesystems.disks.local.root' => $root]);
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
