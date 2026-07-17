<?php

namespace Tests\Feature\Report;

use App\Contracts\Reports\Storage\ReportStorageInterface;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use App\Models\ReportExport;
use App\Services\Reports\ReportExportManager;
use App\Services\Reports\Storage\LocalReportStorage;
use App\Services\Reports\Storage\ReportStorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use ReflectionClass;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class ReportStorageTest extends TestCase
{
    use RefreshDatabase;

    private function fakeDisk(): void
    {
        Storage::fake((string) config('report_exports.default_disk'));
    }

    public function test_storage_interface_binding_resolves_configured_driver(): void
    {
        $storage = $this->app->make(ReportStorageInterface::class);

        $this->assertInstanceOf(LocalReportStorage::class, $storage);
    }

    public function test_storage_manager_resolves_local_driver_by_default(): void
    {
        $manager = $this->app->make(ReportStorageManager::class);

        $this->assertInstanceOf(LocalReportStorage::class, $manager->driver());
        $this->assertInstanceOf(LocalReportStorage::class, $manager->driver('local'));
        $this->assertSame(['local'], $manager->registeredDrivers());
    }

    public function test_storage_manager_rejects_unregistered_drivers(): void
    {
        $manager = $this->app->make(ReportStorageManager::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Report storage driver [s3] is not registered.');

        $manager->driver('s3');
    }

    public function test_configuration_is_loaded_with_defaults(): void
    {
        $this->assertSame('local', config('report_exports.default_disk'));
        $this->assertSame('reports/exports', config('report_exports.exports_directory'));
    }

    public function test_reserve_path_uses_configured_exports_directory(): void
    {
        config(['report_exports.exports_directory' => 'custom/exports']);

        $export = ReportExport::factory()->format(ReportExportFormat::Xlsx)->create();

        $path = $this->app->make(ReportStorageInterface::class)->reservePath($export);

        $this->assertStringStartsWith("custom/exports/{$export->id}/suppliers-", $path);
        $this->assertStringEndsWith('.xlsx', $path);
    }

    public function test_write_placeholder_creates_file_on_configured_disk(): void
    {
        $this->fakeDisk();

        $export = ReportExport::factory()->create();
        $storage = $this->app->make(ReportStorageInterface::class);

        $path = $storage->reservePath($export);
        $storage->writePlaceholder($export, $path);

        Storage::disk((string) config('report_exports.default_disk'))->assertExists($path);
    }

    public function test_exists_reflects_file_presence(): void
    {
        $this->fakeDisk();

        $export = ReportExport::factory()->create();
        $storage = $this->app->make(ReportStorageInterface::class);

        $path = $storage->reservePath($export);

        $this->assertFalse($storage->exists($path));

        $storage->writePlaceholder($export, $path);

        $this->assertTrue($storage->exists($path));
    }

    public function test_delete_removes_export_file(): void
    {
        $this->fakeDisk();

        $export = ReportExport::factory()->create();
        $storage = $this->app->make(ReportStorageInterface::class);

        $path = $storage->reservePath($export);
        $storage->writePlaceholder($export, $path);

        $this->assertTrue($storage->delete($path));
        $this->assertFalse($storage->exists($path));
    }

    public function test_export_manager_writes_through_storage_abstraction(): void
    {
        $spy = new class implements ReportStorageInterface
        {
            public ?string $reservedPath = null;

            public ?string $writtenPath = null;

            public function reservePath(ReportExport $export): string
            {
                return $this->reservedPath = "spy/exports/{$export->id}.{$export->format->fileExtension()}";
            }

            public function writePlaceholder(ReportExport $export, string $path): void
            {
                $this->writtenPath = $path;
            }

            public function exists(string $path): bool
            {
                return $path === $this->writtenPath;
            }

            public function delete(string $path): bool
            {
                return true;
            }

            public function download(ReportExport $export): StreamedResponse
            {
                return new StreamedResponse(fn () => null);
            }
        };

        $this->app->instance(ReportStorageInterface::class, $spy);

        $export = ReportExport::factory()->create();

        $processed = $this->app->make(ReportExportManager::class)->process($export->id);

        $this->assertSame(ReportExportStatus::Completed, $processed->status);
        $this->assertSame($spy->reservedPath, $processed->file_path);
        $this->assertSame($spy->reservedPath, $spy->writtenPath);
    }

    public function test_export_manager_has_no_direct_storage_facade_usage(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ReportExportManager::class))->getFileName(),
        );

        $this->assertStringNotContainsString('Illuminate\Support\Facades\Storage', $source);
        $this->assertStringNotContainsString('Storage::', $source);
    }
}
