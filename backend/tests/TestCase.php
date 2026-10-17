<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Give every phpunit PROCESS its own fake-disk folder, so two test runs started at the same time (for example the
        // SQLite and PostgreSQL suites) cannot empty each other's files. The token is only used by the framework to name
        // that folder here; the parallel-testing database/cache switching also needs LARAVEL_PARALLEL_TESTING, which is not set.
        ParallelTesting::resolveTokenUsing(fn () => $_SERVER['TEST_TOKEN'] ?? 'p'.getmypid());

        // Safety net: whatever a test does through the "local" (private) disk, it must never reach the real application
        // storage folder (storage/app/private). Every test starts with a fresh, EMPTY, isolated fake disk (the framework
        // keeps it under storage/framework/testing/disks/local and empties only that folder), and the configured root is
        // aligned with it so code that reads the configuration sees the same isolated folder.
        Storage::fake('local');
        config(['filesystems.disks.local.root' => rtrim(Storage::disk('local')->path(''), '/\\')]);
    }
}
